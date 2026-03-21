<?php

namespace TraceKit\PHP\Tests\Integrations;

use PHPUnit\Framework\TestCase;
use TraceKit\PHP\Integrations\LlmInstrumentation;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;

/**
 * Integration tests for LlmInstrumentation middleware routing.
 *
 * Validates that the Guzzle middleware correctly routes LLM requests
 * to provider handlers and passes non-LLM requests through unmodified.
 */
class LlmInstrumentationTest extends TestCase
{
    /**
     * Test: Non-LLM request passes through without modification.
     * Requests to hosts that are not recognized LLM providers should
     * be forwarded to the next handler unchanged.
     */
    public function testNonLlmRequestPassesThrough(): void
    {
        $tracer = $this->createMock(\OpenTelemetry\API\Trace\TracerInterface::class);
        // Tracer spanBuilder should NOT be called for non-LLM hosts
        $tracer->expects($this->never())->method('spanBuilder');

        $instrumentation = new LlmInstrumentation($tracer, ['enabled' => true]);
        $middleware = $instrumentation->getGuzzleMiddleware();

        $request = new Request('GET', 'https://api.example.com/data');
        $expectedResponse = new Response(200, [], 'ok');

        $handler = function ($req, $opts) use ($expectedResponse) {
            return new FulfilledPromise($expectedResponse);
        };

        $result = $middleware($handler);
        $promise = $result($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('ok', (string) $response->getBody());
    }

    /**
     * Test: Disabled middleware passes all requests through.
     * When config has 'enabled' => false, even LLM provider requests
     * should pass through without instrumentation.
     */
    public function testDisabledMiddlewarePassesThrough(): void
    {
        $tracer = $this->createMock(\OpenTelemetry\API\Trace\TracerInterface::class);
        $tracer->expects($this->never())->method('spanBuilder');

        $instrumentation = new LlmInstrumentation($tracer, ['enabled' => false]);
        $middleware = $instrumentation->getGuzzleMiddleware();

        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], '{}');
        $expectedResponse = new Response(200, [], '{"id":"chatcmpl-abc"}');

        $handler = function ($req, $opts) use ($expectedResponse) {
            return new FulfilledPromise($expectedResponse);
        };

        $result = $middleware($handler);
        $promise = $result($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test: GET requests to LLM providers pass through unmodified.
     * Only POST requests should be instrumented (chat completions),
     * not GET requests (model listing, etc).
     */
    public function testGetRequestPassesThrough(): void
    {
        $tracer = $this->createMock(\OpenTelemetry\API\Trace\TracerInterface::class);
        // GET should not trigger span creation
        $tracer->expects($this->never())->method('spanBuilder');

        $instrumentation = new LlmInstrumentation($tracer, ['enabled' => true]);
        $middleware = $instrumentation->getGuzzleMiddleware();

        $request = new Request('GET', 'https://api.openai.com/v1/models');
        $expectedResponse = new Response(200, [], '{"data":[]}');

        $handler = function ($req, $opts) use ($expectedResponse) {
            return new FulfilledPromise($expectedResponse);
        };

        $result = $middleware($handler);
        $promise = $result($request, []);
        $response = $promise->wait();

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test: Middleware can be instantiated with default config.
     */
    public function testDefaultConfigInstantiation(): void
    {
        $tracer = $this->createMock(\OpenTelemetry\API\Trace\TracerInterface::class);

        $instrumentation = new LlmInstrumentation($tracer);
        $middleware = $instrumentation->getGuzzleMiddleware();

        $this->assertIsCallable($middleware);
    }

    /**
     * Test: Non-LLM POST request passes through.
     * POST requests to non-LLM hosts should not be instrumented.
     */
    public function testNonLlmPostRequestPassesThrough(): void
    {
        $tracer = $this->createMock(\OpenTelemetry\API\Trace\TracerInterface::class);
        $tracer->expects($this->never())->method('spanBuilder');

        $instrumentation = new LlmInstrumentation($tracer, ['enabled' => true]);
        $middleware = $instrumentation->getGuzzleMiddleware();

        $request = new Request('POST', 'https://api.example.com/webhook', [], '{"event":"test"}');
        $expectedResponse = new Response(202);

        $handler = function ($req, $opts) use ($expectedResponse) {
            return new FulfilledPromise($expectedResponse);
        };

        $result = $middleware($handler);
        $promise = $result($request, []);
        $response = $promise->wait();

        $this->assertEquals(202, $response->getStatusCode());
    }
}
