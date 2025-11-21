<?php

namespace TraceKit\PHP\Instrumentation;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;

class HttpClientInstrumentation
{
    private TracerInterface $tracer;
    private array $serviceNameMappings;

    public function __construct(TracerInterface $tracer, array $serviceNameMappings = [])
    {
        $this->tracer = $tracer;
        $this->serviceNameMappings = $serviceNameMappings;
    }

    /**
     * Wrapper for curl_exec that creates CLIENT spans
     *
     * Usage: Replace curl_exec($ch) with tracekit_curl_exec($ch, $tracekitClient)
     *
     * @param resource $ch cURL handle
     * @return mixed Response data or false on failure
     */
    public function wrapCurlExec($ch)
    {
        // Get URL and method from cURL handle
        $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $method = $this->getCurlMethod($ch);

        // Extract service name from URL
        $serviceName = $this->extractServiceName($url);

        // Start CLIENT span
        $span = $this->tracer
            ->spanBuilder("HTTP {$method}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.url', $url)
            ->setAttribute('http.method', $method)
            ->setAttribute('peer.service', $serviceName)
            ->startSpan();

        $scope = $span->activate();

        try {
            // Inject trace context into headers
            $traceparent = $this->generateTraceparent($span);

            // Get existing headers
            $existingHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
            $headers = [];
            if ($existingHeaders) {
                $headers = explode("\r\n", $existingHeaders);
            }

            // Add traceparent header
            $headers[] = "traceparent: {$traceparent}";
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute request
            $result = curl_exec($ch);

            // Get response status code
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $span->setAttribute('http.status_code', $httpCode);

            if ($httpCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            } else {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($e);
            throw $e;
        } finally {
            @$scope->detach();
            $span->end();
        }
    }

    /**
     * Get Guzzle middleware for automatic instrumentation
     *
     * Usage:
     * $stack = HandlerStack::create();
     * $stack->push($tracekitClient->getHttpClientInstrumentation()->getGuzzleMiddleware());
     * $client = new Client(['handler' => $stack]);
     *
     * @return callable Guzzle middleware
     */
    public function getGuzzleMiddleware(): callable
    {
        return function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                // Extract URL and method
                $url = (string) $request->getUri();
                $method = $request->getMethod();
                $serviceName = $this->extractServiceName($url);

                // Start CLIENT span
                $span = $this->tracer
                    ->spanBuilder("HTTP {$method}")
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute('http.url', $url)
                    ->setAttribute('http.method', $method)
                    ->setAttribute('peer.service', $serviceName)
                    ->startSpan();

                $scope = $span->activate();

                try {
                    // Inject trace context
                    $traceparent = $this->generateTraceparent($span);
                    $request = $request->withHeader('traceparent', $traceparent);

                    // Execute request
                    $promise = $handler($request, $options);

                    return $promise->then(
                        function ($response) use ($span, $scope) {
                            // Success handler
                            $statusCode = $response->getStatusCode();
                            $span->setAttribute('http.status_code', $statusCode);

                            if ($statusCode >= 400) {
                                $span->setStatus(StatusCode::STATUS_ERROR);
                            } else {
                                $span->setStatus(StatusCode::STATUS_OK);
                            }

                            $span->end();
                            @$scope->detach();

                            return $response;
                        },
                        function ($reason) use ($span, $scope) {
                            // Error handler
                            $span->setStatus(StatusCode::STATUS_ERROR);
                            if ($reason instanceof \Throwable) {
                                $span->recordException($reason);
                            }

                            $span->end();
                            @$scope->detach();

                            throw $reason;
                        }
                    );
                } catch (\Throwable $e) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                    $span->recordException($e);
                    $span->end();
                    @$scope->detach();
                    throw $e;
                }
            };
        };
    }

    /**
     * Extract service name from URL
     */
    private function extractServiceName(string $url): string
    {
        $parsed = parse_url($url);
        $hostname = $parsed['host'] ?? 'unknown';
        $port = $parsed['port'] ?? null;
        $hostWithPort = $port ? "{$hostname}:{$port}" : $hostname;

        // First, check if there's a configured mapping for this hostname
        // This allows mapping localhost:port to actual service names
        if (!empty($this->serviceNameMappings)) {
            // Check with port first
            if (isset($this->serviceNameMappings[$hostWithPort])) {
                return $this->serviceNameMappings[$hostWithPort];
            }
            // Check without port
            if (isset($this->serviceNameMappings[$hostname])) {
                return $this->serviceNameMappings[$hostname];
            }
        }

        // Handle Kubernetes service names
        if (str_contains($hostname, '.svc.cluster.local')) {
            $parts = explode('.', $hostname);
            return $parts[0] ?? $hostname;
        }

        // Handle internal domain
        if (str_contains($hostname, '.internal')) {
            $parts = explode('.', $hostname);
            return $parts[0] ?? $hostname;
        }

        // Default: return full hostname
        return $hostname;
    }

    /**
     * Get HTTP method from cURL handle
     */
    private function getCurlMethod($ch): string
    {
        // CURLINFO_REQUEST_METHOD requires PHP 7.3+ with libcurl 7.62.0+
        if (defined('CURLINFO_REQUEST_METHOD')) {
            $method = curl_getinfo($ch, CURLINFO_REQUEST_METHOD);
            if (!empty($method)) {
                return strtoupper($method);
            }
        }

        // Fallback: default to GET (most common for our use case)
        // Note: POST detection via CURLOPT_POST is unreliable after exec
        return 'GET';
    }

    /**
     * Generate W3C traceparent header
     */
    private function generateTraceparent($span): string
    {
        $traceId = $span->getContext()->getTraceId();
        $spanId = $span->getContext()->getSpanId();
        $flags = $span->getContext()->getTraceFlags();

        return sprintf('00-%s-%s-%02x', $traceId, $spanId, $flags);
    }
}
