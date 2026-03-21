<?php

namespace TraceKit\PHP\Integrations;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Anthropic-specific request/response handler for LLM instrumentation.
 *
 * Called by the LLM Guzzle middleware when the request host is api.anthropic.com.
 * Handles non-streaming responses, streaming SSE with Anthropic event types
 * (message_start, content_block_start, content_block_delta, message_delta, message_stop),
 * tool_use detection, cache token tracking, and content capture.
 *
 * All instrumentation is wrapped in try/catch to never break user code.
 */
class AnthropicHandler
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly array $config
    ) {
    }

    /**
     * Main entry point: instrument an Anthropic request through Guzzle.
     *
     * @param RequestInterface $request  The outgoing HTTP request
     * @param callable         $handler  The next Guzzle handler
     * @param array            $options  Guzzle request options
     * @return PromiseInterface
     */
    public function handleRequest(RequestInterface $request, callable $handler, array $options): PromiseInterface
    {
        // Parse request body
        $bodyStr = (string) $request->getBody();
        $body = json_decode($bodyStr, true) ?: [];

        // Rewind request body so downstream handler can read it
        $request = $request->withBody(Utils::streamFor($bodyStr));

        $model = $body['model'] ?? 'unknown';
        $maxTokens = isset($body['max_tokens']) ? (int) $body['max_tokens'] : null;
        $temperature = isset($body['temperature']) ? (float) $body['temperature'] : null;
        $topP = isset($body['top_p']) ? (float) $body['top_p'] : null;
        $isStreaming = !empty($body['stream']);
        $messages = $body['messages'] ?? [];
        $system = $body['system'] ?? null;

        // Create the span
        $span = $this->tracer
            ->spanBuilder("chat {$model}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $captureContent = LlmCommon::shouldCaptureContent($this->config);

        try {
            // Set request attributes
            LlmCommon::setGenAIRequestAttributes(
                $span,
                'anthropic',
                $model,
                $maxTokens,
                $temperature,
                $topP
            );

            // Capture input content if enabled
            if ($captureContent) {
                // Anthropic system is a top-level field (string or array)
                if ($system !== null) {
                    LlmCommon::captureSystemInstructions($span, $system);
                }
                if (!empty($messages)) {
                    LlmCommon::captureInputMessages($span, $messages);
                }
            }
        } catch (\Throwable $e) {
            // Never break user code during attribute setting
        }

        // Execute the actual HTTP request
        $promise = $handler($request, $options);

        return $promise->then(
            function (ResponseInterface $response) use ($span, $isStreaming, $captureContent): ResponseInterface {
                try {
                    if ($isStreaming) {
                        return $this->wrapStreamingResponse($span, $response, $captureContent);
                    }
                    return $this->handleNonStreamingResponse($span, $response, $captureContent);
                } catch (\Throwable $e) {
                    // If instrumentation fails, just end the span and pass through
                    try {
                        $span->end();
                    } catch (\Throwable $_) {
                    }
                    return $response;
                }
            },
            function (\Throwable $reason) use ($span): void {
                try {
                    LlmCommon::setGenAIErrorAttributes($span, $reason);
                    $span->end();
                } catch (\Throwable $_) {
                    try {
                        $span->end();
                    } catch (\Throwable $_) {
                    }
                }
                throw $reason;
            }
        );
    }

    /**
     * Handle a non-streaming Anthropic response.
     *
     * Extracts model, id, stop_reason (Anthropic-specific), token usage,
     * cache tokens, tool_use content blocks, and optionally captures output content.
     */
    private function handleNonStreamingResponse(
        SpanInterface $span,
        ResponseInterface $response,
        bool $captureContent
    ): ResponseInterface {
        $bodyStr = (string) $response->getBody();
        $data = json_decode($bodyStr, true) ?: [];

        // Set response attributes
        $stopReason = $data['stop_reason'] ?? null;

        LlmCommon::setGenAIResponseAttributes(
            $span,
            $data['model'] ?? null,
            $data['id'] ?? null,
            $stopReason !== null ? [$stopReason] : null,
            $data['usage']['input_tokens'] ?? null,
            $data['usage']['output_tokens'] ?? null
        );

        // Anthropic cache token attributes
        $usage = $data['usage'] ?? [];
        if (isset($usage['cache_creation_input_tokens'])) {
            $span->setAttribute('gen_ai.usage.cache_creation.input_tokens', (int) $usage['cache_creation_input_tokens']);
        }
        if (isset($usage['cache_read_input_tokens'])) {
            $span->setAttribute('gen_ai.usage.cache_read.input_tokens', (int) $usage['cache_read_input_tokens']);
        }

        // Record tool calls from content blocks
        $contentBlocks = $data['content'] ?? [];
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                LlmCommon::recordToolCallEvent(
                    $span,
                    $block['name'] ?? 'unknown',
                    $block['id'] ?? null,
                    isset($block['input']) ? json_encode($block['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null
                );
            }
        }

        // Capture output content if enabled
        if ($captureContent && !empty($contentBlocks)) {
            LlmCommon::captureOutputMessages($span, $contentBlocks);
        }

        $span->end();

        // Return response with rewound body
        return $response->withBody(Utils::streamFor($bodyStr));
    }

    /**
     * Wrap a streaming Anthropic response to accumulate attributes.
     *
     * Returns a new Response with a wrapped body stream that parses Anthropic SSE events
     * (event: + data: line pairs), accumulates token usage / tool calls / content,
     * and finalizes the span when the stream completes.
     */
    private function wrapStreamingResponse(
        SpanInterface $span,
        ResponseInterface $response,
        bool $captureContent
    ): ResponseInterface {
        $wrappedBody = new AnthropicStreamWrapper(
            $response->getBody(),
            $span,
            $captureContent
        );

        return new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            $wrappedBody
        );
    }
}

/**
 * PSR-7 StreamInterface wrapper that parses Anthropic SSE events,
 * accumulates token usage, tool calls, and content, and finalizes
 * the span when the stream is fully consumed.
 *
 * Anthropic SSE format uses `event:` line followed by `data:` line,
 * which differs from OpenAI's data-only format.
 *
 * Event types handled:
 * - message_start: model, id, input_tokens, cache tokens
 * - content_block_start: tool_use block detection
 * - content_block_delta: text_delta and input_json_delta accumulation
 * - message_delta: stop_reason, output_tokens
 * - message_stop: stream completion signal
 *
 * @internal Used by AnthropicHandler only.
 */
class AnthropicStreamWrapper implements StreamInterface
{
    private string $buffer = '';
    private bool $finalized = false;
    private ?string $currentEventType = null;

    // Accumulated state
    private ?string $responseModel = null;
    private ?string $responseId = null;
    private ?string $stopReason = null;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;
    private ?int $cacheCreationInputTokens = null;
    private ?int $cacheReadInputTokens = null;
    /** @var string[] */
    private array $contentChunks = [];
    /** @var array<int, array{name: string, id: ?string, arguments: string}> */
    private array $toolCalls = [];

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly SpanInterface $span,
        private readonly bool $captureContent
    ) {
    }

    public function read(int $length): string
    {
        $data = $this->inner->read($length);

        if ($data !== '') {
            $this->buffer .= $data;
            $this->parseBuffer();
        }

        // Check if the stream has ended
        if ($this->inner->eof() && !$this->finalized) {
            if ($this->buffer !== '') {
                $this->parseBuffer();
            }
            $this->finalizeSpan();
        }

        return $data;
    }

    public function getContents(): string
    {
        $data = $this->inner->getContents();

        if ($data !== '') {
            $this->buffer .= $data;
            $this->parseBuffer();
        }

        if (!$this->finalized) {
            $this->finalizeSpan();
        }

        return $data;
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function close(): void
    {
        if (!$this->finalized) {
            $this->finalizeSpan();
        }
        $this->inner->close();
    }

    public function detach()
    {
        if (!$this->finalized) {
            $this->finalizeSpan();
        }
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return null; // Streaming -- size unknown
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // SSE parsing — Anthropic uses event:/data: line pairs
    // ------------------------------------------------------------------

    /**
     * Parse complete SSE lines from the buffer and process events.
     *
     * Anthropic SSE format:
     *   event: message_start
     *   data: {"type":"message_start","message":{...}}
     *
     *   event: content_block_delta
     *   data: {"type":"content_block_delta","index":0,"delta":{...}}
     */
    private function parseBuffer(): void
    {
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            $line = trim($line);

            if ($line === '') {
                // Empty line resets event type for the next event
                $this->currentEventType = null;
                continue;
            }

            // Handle event type lines
            if (str_starts_with($line, 'event:')) {
                $this->currentEventType = trim(substr($line, 6));
                continue;
            }

            // Handle data lines
            if (str_starts_with($line, 'data:')) {
                $payload = trim(substr($line, 5));

                $data = json_decode($payload, true);
                if ($data !== null) {
                    $this->processEvent($this->currentEventType, $data);
                }
            }
        }
    }

    /**
     * Process a single Anthropic SSE event.
     */
    private function processEvent(?string $eventType, array $data): void
    {
        try {
            switch ($eventType) {
                case 'message_start':
                    $this->handleMessageStart($data);
                    break;

                case 'content_block_start':
                    $this->handleContentBlockStart($data);
                    break;

                case 'content_block_delta':
                    $this->handleContentBlockDelta($data);
                    break;

                case 'message_delta':
                    $this->handleMessageDelta($data);
                    break;

                case 'message_stop':
                    // Stream complete signal — finalization happens on EOF
                    break;
            }
        } catch (\Throwable $e) {
            // Never fail on event processing
        }
    }

    /**
     * Handle message_start event.
     *
     * data: {"type":"message_start","message":{"model":"claude-sonnet-4-6","id":"msg_...","usage":{"input_tokens":N,...}}}
     */
    private function handleMessageStart(array $data): void
    {
        $message = $data['message'] ?? [];

        if (!empty($message['model'])) {
            $this->responseModel = $message['model'];
        }
        if (!empty($message['id'])) {
            $this->responseId = $message['id'];
        }

        $usage = $message['usage'] ?? [];
        if (isset($usage['input_tokens'])) {
            $this->inputTokens = (int) $usage['input_tokens'];
        }
        if (isset($usage['cache_creation_input_tokens'])) {
            $this->cacheCreationInputTokens = (int) $usage['cache_creation_input_tokens'];
        }
        if (isset($usage['cache_read_input_tokens'])) {
            $this->cacheReadInputTokens = (int) $usage['cache_read_input_tokens'];
        }
    }

    /**
     * Handle content_block_start event.
     *
     * data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","name":"get_weather","id":"toolu_..."}}
     */
    private function handleContentBlockStart(array $data): void
    {
        $index = $data['index'] ?? 0;
        $block = $data['content_block'] ?? [];

        if (($block['type'] ?? '') === 'tool_use') {
            $this->toolCalls[$index] = [
                'name' => $block['name'] ?? 'unknown',
                'id' => $block['id'] ?? null,
                'arguments' => '',
            ];
        }
    }

    /**
     * Handle content_block_delta event.
     *
     * text_delta: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}
     * input_json_delta: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"loc"}}
     */
    private function handleContentBlockDelta(array $data): void
    {
        $index = $data['index'] ?? 0;
        $delta = $data['delta'] ?? [];
        $deltaType = $delta['type'] ?? '';

        if ($deltaType === 'text_delta' && isset($delta['text'])) {
            if ($this->captureContent) {
                $this->contentChunks[] = $delta['text'];
            }
        } elseif ($deltaType === 'input_json_delta' && isset($delta['partial_json'])) {
            // Accumulate tool call arguments by index
            if (isset($this->toolCalls[$index])) {
                $this->toolCalls[$index]['arguments'] .= $delta['partial_json'];
            }
        }
    }

    /**
     * Handle message_delta event.
     *
     * data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":N}}
     */
    private function handleMessageDelta(array $data): void
    {
        $delta = $data['delta'] ?? [];
        if (!empty($delta['stop_reason'])) {
            $this->stopReason = $delta['stop_reason'];
        }

        $usage = $data['usage'] ?? [];
        if (isset($usage['output_tokens'])) {
            $this->outputTokens = (int) $usage['output_tokens'];
        }
    }

    /**
     * Finalize the span with accumulated attributes when the stream ends.
     */
    private function finalizeSpan(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;

        try {
            LlmCommon::setGenAIResponseAttributes(
                $this->span,
                $this->responseModel,
                $this->responseId,
                $this->stopReason !== null ? [$this->stopReason] : null,
                $this->inputTokens,
                $this->outputTokens
            );

            // Set Anthropic cache token attributes
            if ($this->cacheCreationInputTokens !== null) {
                $this->span->setAttribute('gen_ai.usage.cache_creation.input_tokens', $this->cacheCreationInputTokens);
            }
            if ($this->cacheReadInputTokens !== null) {
                $this->span->setAttribute('gen_ai.usage.cache_read.input_tokens', $this->cacheReadInputTokens);
            }

            // Record accumulated tool calls as span events
            foreach ($this->toolCalls as $tc) {
                LlmCommon::recordToolCallEvent(
                    $this->span,
                    $tc['name'],
                    $tc['id'],
                    !empty($tc['arguments']) ? $tc['arguments'] : null
                );
            }

            // Capture accumulated output if enabled
            if ($this->captureContent && !empty($this->contentChunks)) {
                $fullContent = implode('', $this->contentChunks);
                LlmCommon::captureOutputMessages(
                    $this->span,
                    [['type' => 'text', 'text' => $fullContent]]
                );
            }
        } catch (\Throwable $e) {
            // Never break user code
        }

        try {
            $this->span->end();
        } catch (\Throwable $e) {
            // Span may already be ended
        }
    }
}
