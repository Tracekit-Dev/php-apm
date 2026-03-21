<?php

namespace TraceKit\PHP\Integrations;

use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Http\Message\RequestInterface;

/**
 * LLM instrumentation orchestrator for Guzzle HTTP client.
 *
 * Provides a Guzzle middleware that detects LLM API requests by host,
 * routes them to the appropriate provider handler (OpenAI or Anthropic),
 * and passes non-LLM requests through unmodified.
 *
 * Usage:
 *   $llm = new LlmInstrumentation($tracer, ['capture_content' => true]);
 *   $stack = HandlerStack::create();
 *   $stack->push($llm->getGuzzleMiddleware());
 *   $client = new Client(['handler' => $stack]);
 */
class LlmInstrumentation
{
    private TracerInterface $tracer;
    private array $config;
    private ?OpenAiHandler $openAiHandler = null;
    private ?AnthropicHandler $anthropicHandler = null;

    public function __construct(TracerInterface $tracer, array $config = [])
    {
        $this->tracer = $tracer;
        $this->config = array_merge([
            'enabled' => true,
            'openai' => true,
            'anthropic' => true,
            'capture_content' => false,
        ], $config);

        // Initialize handlers only for enabled providers
        if ($this->config['openai']) {
            $this->openAiHandler = new OpenAiHandler($tracer, $this->config);
        }
        if ($this->config['anthropic']) {
            $this->anthropicHandler = new AnthropicHandler($tracer, $this->config);
        }
    }

    /**
     * Get a Guzzle middleware that auto-instruments LLM API calls.
     *
     * The middleware inspects each request's host to detect the LLM provider
     * (via LlmCommon::detectProvider), then delegates to the appropriate handler.
     * Non-LLM requests and non-POST requests pass through unmodified.
     *
     * @return callable Guzzle middleware
     */
    public function getGuzzleMiddleware(): callable
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                if (!$this->config['enabled']) {
                    return $handler($request, $options);
                }

                try {
                    $host = $request->getUri()->getHost();
                    $provider = LlmCommon::detectProvider($host);

                    if ($provider === null) {
                        return $handler($request, $options);
                    }

                    // Only instrument POST requests (chat completions, not model listing)
                    if (strtoupper($request->getMethod()) !== 'POST') {
                        return $handler($request, $options);
                    }

                    switch ($provider) {
                        case 'openai':
                            if ($this->openAiHandler) {
                                return $this->openAiHandler->handleRequest($request, $handler, $options);
                            }
                            break;
                        case 'anthropic':
                            if ($this->anthropicHandler) {
                                return $this->anthropicHandler->handleRequest($request, $handler, $options);
                            }
                            break;
                    }
                } catch (\Throwable $e) {
                    // Never break user code -- silently fall through
                }

                return $handler($request, $options);
            };
        };
    }
}
