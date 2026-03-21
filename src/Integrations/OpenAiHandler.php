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
 * OpenAI-specific request/response handler for LLM instrumentation.
 *
 * Called by the LLM Guzzle middleware when the request host is api.openai.com.
 * Handles non-streaming responses, streaming SSE with token accumulation,
 * tool call detection, and content capture.
 *
 * All instrumentation is wrapped in try/catch to never break user code.
 */
class OpenAiHandler
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly array $config
    ) {
    }

    /**
     * Main entry point: instrument an OpenAI request through Guzzle.
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
        $maxTokens = $body['max_tokens'] ?? $body['max_completion_tokens'] ?? null;
        $temperature = isset($body['temperature']) ? (float) $body['temperature'] : null;
        $topP = isset($body['top_p']) ? (float) $body['top_p'] : null;
        $isStreaming = !empty($body['stream']);
        $messages = $body['messages'] ?? [];

        // For streaming: inject stream_options.include_usage if not already set
        if ($isStreaming) {
            if (!isset($body['stream_options'])) {
                $body['stream_options'] = ['include_usage' => true];
            } elseif (!isset($body['stream_options']['include_usage'])) {
                $body['stream_options']['include_usage'] = true;
            }
            // Rebuild request with modified body
            $request = $request->withBody(
                Utils::streamFor(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            );
        }

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
                'openai',
                $model,
                $maxTokens !== null ? (int) $maxTokens : null,
                $temperature,
                $topP
            );

            // Capture input content if enabled
            if ($captureContent && !empty($messages)) {
                $systemMessages = array_values(array_filter(
                    $messages,
                    fn(array $m) => ($m['role'] ?? '') === 'system'
                ));
                $nonSystemMessages = array_values(array_filter(
                    $messages,
                    fn(array $m) => ($m['role'] ?? '') !== 'system'
                ));

                if (!empty($systemMessages)) {
                    LlmCommon::captureSystemInstructions($span, $systemMessages);
                }
                LlmCommon::captureInputMessages($span, $nonSystemMessages);
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
     * Handle a non-streaming OpenAI response.
     *
     * Extracts model, id, finish reasons, token usage, tool calls,
     * and optionally captures output content.
     */
    private function handleNonStreamingResponse(
        SpanInterface $span,
        ResponseInterface $response,
        bool $captureContent
    ): ResponseInterface {
        $bodyStr = (string) $response->getBody();
        $data = json_decode($bodyStr, true) ?: [];

        // Set response attributes
        $finishReasons = [];
        foreach (($data['choices'] ?? []) as $choice) {
            if (!empty($choice['finish_reason'])) {
                $finishReasons[] = $choice['finish_reason'];
            }
        }

        LlmCommon::setGenAIResponseAttributes(
            $span,
            $data['model'] ?? null,
            $data['id'] ?? null,
            !empty($finishReasons) ? $finishReasons : null,
            $data['usage']['prompt_tokens'] ?? null,
            $data['usage']['completion_tokens'] ?? null
        );

        // OpenAI-specific: system_fingerprint
        if (!empty($data['system_fingerprint'])) {
            $span->setAttribute('openai.response.system_fingerprint', $data['system_fingerprint']);
        }

        // Record tool calls as span events
        foreach (($data['choices'] ?? []) as $choice) {
            foreach (($choice['message']['tool_calls'] ?? []) as $tc) {
                LlmCommon::recordToolCallEvent(
                    $span,
                    $tc['function']['name'] ?? 'unknown',
                    $tc['id'] ?? null,
                    $tc['function']['arguments'] ?? null
                );
            }
        }

        // Capture output content if enabled
        if ($captureContent) {
            $outputMessages = [];
            foreach (($data['choices'] ?? []) as $choice) {
                if (isset($choice['message'])) {
                    $outputMessages[] = $choice['message'];
                }
            }
            if (!empty($outputMessages)) {
                LlmCommon::captureOutputMessages($span, $outputMessages);
            }
        }

        $span->end();

        // Return response with rewound body
        return $response->withBody(Utils::streamFor($bodyStr));
    }

    /**
     * Wrap a streaming OpenAI response to accumulate attributes.
     *
     * Returns a new Response with a wrapped body stream that parses SSE lines,
     * accumulates token usage / tool calls / content, and finalizes the span
     * when the stream completes.
     */
    private function wrapStreamingResponse(
        SpanInterface $span,
        ResponseInterface $response,
        bool $captureContent
    ): ResponseInterface {
        $wrappedBody = new OpenAiStreamWrapper(
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
 * PSR-7 StreamInterface wrapper that parses OpenAI SSE chunks,
 * accumulates token usage, tool calls, and content, and finalizes
 * the span when the stream is fully consumed.
 *
 * @internal Used by OpenAiHandler only.
 */
class OpenAiStreamWrapper implements StreamInterface
{
    private string $buffer = '';
    private bool $finalized = false;

    // Accumulated state
    private ?string $responseModel = null;
    private ?string $responseId = null;
    private ?string $systemFingerprint = null;
    private ?string $finishReason = null;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;
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
            // Process any remaining buffer content
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
        return null; // Streaming — size unknown
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
    // SSE parsing and chunk processing
    // ------------------------------------------------------------------

    /**
     * Parse complete SSE lines from the buffer and process JSON chunks.
     */
    private function parseBuffer(): void
    {
        // SSE format: "data: {...}\n\n"
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Handle SSE data lines
            if (str_starts_with($line, 'data: ')) {
                $payload = substr($line, 6);

                if ($payload === '[DONE]') {
                    // Stream complete signal
                    if (!$this->finalized) {
                        $this->finalizeSpan();
                    }
                    continue;
                }

                $chunk = json_decode($payload, true);
                if ($chunk !== null) {
                    $this->processChunk($chunk);
                }
            }
        }
    }

    /**
     * Process a single parsed OpenAI streaming chunk.
     */
    private function processChunk(array $chunk): void
    {
        try {
            // Extract model, id, system_fingerprint from any chunk
            if (!empty($chunk['model'])) {
                $this->responseModel = $chunk['model'];
            }
            if (!empty($chunk['id'])) {
                $this->responseId = $chunk['id'];
            }
            if (!empty($chunk['system_fingerprint'])) {
                $this->systemFingerprint = $chunk['system_fingerprint'];
            }

            // Extract usage from the final chunk (when stream_options.include_usage is set)
            if (isset($chunk['usage'])) {
                if (isset($chunk['usage']['prompt_tokens'])) {
                    $this->inputTokens = (int) $chunk['usage']['prompt_tokens'];
                }
                if (isset($chunk['usage']['completion_tokens'])) {
                    $this->outputTokens = (int) $chunk['usage']['completion_tokens'];
                }
            }

            // Process choices
            foreach (($chunk['choices'] ?? []) as $choice) {
                if (!empty($choice['finish_reason'])) {
                    $this->finishReason = $choice['finish_reason'];
                }

                $delta = $choice['delta'] ?? [];

                // Accumulate content for capture
                if ($this->captureContent && !empty($delta['content'])) {
                    $this->contentChunks[] = $delta['content'];
                }

                // Accumulate tool calls (indexed by tc.index)
                if (!empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;

                        if (isset($this->toolCalls[$idx])) {
                            // Concatenate function arguments
                            if (!empty($tc['function']['arguments'])) {
                                $this->toolCalls[$idx]['arguments'] .= $tc['function']['arguments'];
                            }
                        } else {
                            $this->toolCalls[$idx] = [
                                'name' => $tc['function']['name'] ?? 'unknown',
                                'id' => $tc['id'] ?? null,
                                'arguments' => $tc['function']['arguments'] ?? '',
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never fail on chunk processing
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
                $this->finishReason !== null ? [$this->finishReason] : null,
                $this->inputTokens,
                $this->outputTokens
            );

            if ($this->systemFingerprint !== null) {
                $this->span->setAttribute('openai.response.system_fingerprint', $this->systemFingerprint);
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
                    [['role' => 'assistant', 'content' => $fullContent]]
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
