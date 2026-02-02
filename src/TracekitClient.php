<?php

namespace TraceKit\PHP;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

use TraceKit\PHP\SnapshotClient;
use TraceKit\PHP\Instrumentation\HttpClientInstrumentation;

/**
 * Custom span processor that sends traces to Local UI
 */
class LocalUISpanProcessor implements SpanProcessorInterface
{
    private array $spans = [];
    private const LOCAL_UI_TRACES_URL = 'http://localhost:9999/v1/traces';
    private bool $hasLogged = false;

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        // No action needed on start
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        // Collect span data
        $this->spans[] = $span;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if (empty($this->spans)) {
            return true;
        }

        try {
            // Convert spans to OTLP format
            $exporter = new SpanExporter(
                (new OtlpHttpTransportFactory())->create(
                    self::LOCAL_UI_TRACES_URL,
                    'application/json'
                )
            );

            // Convert ReadableSpanInterface to SpanDataInterface
            $spanData = array_map(function($span) {
                return $span->toSpanData();
            }, $this->spans);

            // Export the span data
            $exporter->export($spanData);

            if (!$this->hasLogged) {
                error_log('🔍 Sent traces to Local UI');
                $this->hasLogged = true;
            }

            $this->spans = [];
            return true;
        } catch (\Exception $e) {
            // Silently fail - don't block trace sending
            error_log('Failed to send traces to Local UI: ' . $e->getMessage());
            $this->spans = [];
            return false;
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->forceFlush($cancellation);
    }
}

class TracekitClient
{
    private TracerProviderInterface $tracerProvider;
    private TracerInterface $tracer;
    private string $endpoint;
    private string $apiKey;
    private string $serviceName;
    private bool $enabled;
    private float $sampleRate;
    private array $serviceNameMappings;
    private ?SnapshotClient $snapshotClient = null;
    private ?HttpClientInstrumentation $httpClientInstrumentation = null;
    private ?MetricsRegistry $metricsRegistry = null;
    private TraceContextPropagator $propagator;

    public function __construct(array $config = [])
    {
        // Set defaults
        $baseEndpoint = $config['endpoint'] ?? 'app.tracekit.dev';
        $tracesPath = $config['traces_path'] ?? '/v1/traces';
        $metricsPath = $config['metrics_path'] ?? '/v1/metrics';

        $this->apiKey = $config['api_key'] ?? '';
        $this->serviceName = $config['service_name'] ?? 'php-app';
        $this->enabled = $config['enabled'] ?? true;
        $this->sampleRate = $config['sample_rate'] ?? 1.0;
        $this->serviceNameMappings = $config['service_name_mappings'] ?? [];
        $this->propagator = TraceContextPropagator::getInstance();

        // Resolve endpoints
        $useSSL = !str_starts_with($baseEndpoint, 'http://');
        $this->endpoint = $this->resolveEndpoint($baseEndpoint, $tracesPath, $useSSL);
        $metricsEndpoint = $this->resolveEndpoint($baseEndpoint, $metricsPath, $useSSL);
        $baseUrl = $this->resolveEndpoint($baseEndpoint, '', $useSSL);

        // Suppress OpenTelemetry error output (export failures, etc.) in development
        // Set 'suppress_errors' => false in production to see export errors
        if ($config['suppress_errors'] ?? true) {
            $this->suppressOpenTelemetryErrors();
        }

        // Initialize metrics registry
        $this->metricsRegistry = new MetricsRegistry($metricsEndpoint, $this->apiKey, $this->serviceName);

        // Initialize code monitoring if enabled
        if (($config['code_monitoring_enabled'] ?? false) && $this->apiKey) {
            $this->snapshotClient = new SnapshotClient(
                $this->apiKey,
                $baseUrl,
                $this->serviceName,
                $config['code_monitoring_max_depth'] ?? 3,
                $config['code_monitoring_max_string'] ?? 1000
            );
        }

        // Create resource with service name
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => $this->serviceName,
            ]))
        );

        // Create span processors
        $spanProcessors = [];

        // Add Local UI processor in development environments
        $env = getenv('ENV') ?: getenv('ENVIRONMENT');
        if ($env === 'development' && $this->detectLocalUI()) {
            $spanProcessors[] = new LocalUISpanProcessor();
        }

        // Add cloud API processor if enabled and API key exists
        if ($this->enabled && $this->apiKey) {
            // Configure OTLP HTTP transport
            $transportFactory = new OtlpHttpTransportFactory();
            $transport = $transportFactory->create(
                $this->endpoint,
                'application/json',
                [
                    'X-API-Key' => $this->apiKey,
                ]
            );

            // Create OTLP exporter with transport
            $exporter = new SpanExporter($transport);

            // Create span processor
            $spanProcessors[] = new SimpleSpanProcessor($exporter);
        }

        // Initialize tracer provider with processors
        $this->tracerProvider = new TracerProvider(
            $spanProcessors,
            null,
            $resource
        );

        // Get tracer instance
        $this->tracer = $this->tracerProvider->getTracer(
            'tracekit-php',
            '1.0.0'
        );

        // Initialize HTTP client instrumentation with service name mappings
        $this->httpClientInstrumentation = new HttpClientInstrumentation(
            $this->tracer,
            $this->serviceNameMappings
        );
    }

    /**
     * Start a new trace (root span) for a server request
     * This automatically activates the span in the context
     * Automatically captures client IP if not provided in attributes
     *
     * @return array ['span' => SpanInterface, 'scope' => int] Scope token for cleanup
     */
    public function startTrace(string $operationName, array $attributes = []): array
    {
        // Automatically add client IP if not already present
        if (!isset($attributes['http.client_ip'])) {
            $clientIp = self::extractClientIp();
            if ($clientIp !== null) {
                $attributes['http.client_ip'] = $clientIp;
            }
        }

        $span = $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($this->normalizeAttributes($attributes))
            ->startSpan();

        // Activate this span in the context so child spans can inherit it
        $scope = $span->activate();

        return [
            'span' => $span,
            'scope' => $scope,
        ];
    }

    /**
     * Extract trace context from request headers (W3C Trace Context)
     *
     * @param array $headers Associative array of headers (keys should be lowercase)
     * @return ContextInterface The extracted context (or root context if no traceparent)
     */
    public function extractTraceparent(array $headers): ContextInterface
    {
        // Normalize header keys to lowercase
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = is_array($value) ? $value[0] : $value;
        }
        return $this->propagator->extract($normalizedHeaders);
    }

    /**
     * Start a SERVER span with optional parent context from traceparent header
     * This is used by middleware to create spans that are children of incoming trace context
     * Automatically captures client IP if not provided in attributes
     *
     * @return array ['span' => SpanInterface, 'scope' => int] Scope token for cleanup
     */
    public function startServerSpan(
        string $operationName,
        array $attributes = [],
        ?ContextInterface $parentContext = null
    ): array {
        // Automatically add client IP if not already present
        if (!isset($attributes['http.client_ip'])) {
            $clientIp = self::extractClientIp();
            if ($clientIp !== null) {
                $attributes['http.client_ip'] = $clientIp;
            }
        }

        $builder = $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($this->normalizeAttributes($attributes));

        if ($parentContext !== null) {
            $builder->setParent($parentContext);
        }

        $span = $builder->startSpan();

        // Activate this span in the context so child spans can inherit it
        $scope = $span->activate();

        return [
            'span' => $span,
            'scope' => $scope,
        ];
    }

    /**
     * Start a child span
     * Automatically inherits from the currently active span in context
     *
     * @return array ['span' => SpanInterface, 'scope' => int] Scope token for cleanup
     */
    public function startSpan(
        string $operationName,
        array $attributes = []
    ): array {
        // The span builder automatically uses the active span from context as parent
        $span = $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes($this->normalizeAttributes($attributes))
            ->startSpan();

        // Activate this span so its children can inherit it
        $scope = $span->activate();

        return [
            'span' => $span,
            'scope' => $scope,
        ];
    }

    /**
     * End a span and detach its scope from the context
     */
    public function endSpan(array $spanData, array $finalAttributes = [], ?string $status = 'OK'): void
    {
        $span = $spanData['span'];
        $scope = $spanData['scope'];

        // Add final attributes
        if (!empty($finalAttributes)) {
            $span->setAttributes($this->normalizeAttributes($finalAttributes));
        }

        // Set status
        if ($status === 'ERROR') {
            $span->setStatus(StatusCode::STATUS_ERROR);
        } elseif ($status === 'OK') {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        $span->end();

        // Detach the scope to restore the previous context
        // Suppress scope ordering warnings from OpenTelemetry debug mode
        @$scope->detach();
    }

    public function addEvent(array $spanData, string $name, array $attributes = []): void
    {
        $spanData['span']->addEvent($name, $this->normalizeAttributes($attributes));
    }

    public function recordException(array $spanData, \Throwable $exception): void
    {
        $span = $spanData['span'];
        
        // Format stack trace for code discovery
        $stacktrace = $this->formatStackTrace($exception);
        
        // Add exception event with formatted stack trace
        $span->addEvent('exception', [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $stacktrace,
        ]);
        
        // Also use OpenTelemetry's built-in exception recording
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }
    
    /**
     * Format exception stack trace for code discovery
     */
    private function formatStackTrace(\Throwable $exception): string
    {
        $frames = [];
        // First line: where the exception was thrown
        $frames[] = $exception->getFile() . ':' . $exception->getLine();
        
        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            
            if ($class && $function) {
                $function = $class . '::' . $function;
            }
            
            // Only include frames that have file information
            if ($file && $function) {
                // Format: "FunctionName at /path/file.php:42"
                $frames[] = sprintf('%s at %s:%d', $function, $file, $line);
            } elseif ($file) {
                // Format: "/path/file.php:42"
                $frames[] = sprintf('%s:%d', $file, $line);
            }
        }
        
        return implode("\n", $frames);
    }

    public function flush(): void
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            $this->tracerProvider->forceFlush();
        }
    }

    public function getTracer(): TracerInterface
    {
        return $this->tracer;
    }

    public function shouldSample(): bool
    {
        return mt_rand() / mt_getrandmax() < $this->sampleRate;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    // Code monitoring methods

    /**
     * Get the snapshot client instance
     */
    public function getSnapshotClient(): ?SnapshotClient
    {
        return $this->snapshotClient;
    }

    /**
     * Check if code monitoring is enabled
     */
    public function isCodeMonitoringEnabled(): bool
    {
        return $this->snapshotClient !== null;
    }

    /**
     * Capture a snapshot with automatic breakpoint detection
     */
    public function captureSnapshot(string $label, array $variables = [], ?array $requestContext = null): void
    {
        if ($this->snapshotClient) {
            $this->snapshotClient->checkAndCaptureWithContext($requestContext, $label, $variables);
        }
    }

    /**
     * Poll for active breakpoints (call this periodically)
     */
    public function pollBreakpoints(): void
    {
        if ($this->snapshotClient) {
            $this->snapshotClient->pollOnce();
        }
    }

    /**
     * Get HTTP client instrumentation helper
     *
     * Use this to wrap cURL calls or get Guzzle middleware:
     *
     * cURL:
     * $result = $tracekitClient->getHttpClientInstrumentation()->wrapCurlExec($ch);
     *
     * Guzzle:
     * $stack = HandlerStack::create();
     * $stack->push($tracekitClient->getHttpClientInstrumentation()->getGuzzleMiddleware());
     * $client = new Client(['handler' => $stack]);
     */
    public function getHttpClientInstrumentation(): ?HttpClientInstrumentation
    {
        return $this->httpClientInstrumentation;
    }

    /**
     * Extract client IP address from HTTP request.
     *
     * Checks X-Forwarded-For, X-Real-IP headers (for proxied requests)
     * and falls back to REMOTE_ADDR.
     *
     * This function is automatically used by TraceKit middleware/integrations
     * to add client IP to all traces for DDoS detection and traffic analysis.
     *
     * @return string|null Client IP address or null if not found
     *
     * @example
     * // Basic usage in vanilla PHP
     * $clientIp = TracekitClient::extractClientIp();
     * $span = $tracekit->startTrace('process-request', [
     *     'http.method' => $_SERVER['REQUEST_METHOD'],
     *     'http.url' => $_SERVER['REQUEST_URI'],
     *     'http.client_ip' => $clientIp,
     * ]);
     *
     * @example
     * // Usage with custom headers (Laravel, Symfony, etc.)
     * $clientIp = TracekitClient::extractClientIp($request->headers->all());
     */
    public static function extractClientIp(?array $headers = null): ?string
    {
        // If no headers provided, use $_SERVER
        if ($headers === null) {
            // Check X-Forwarded-For header (for requests behind proxy/load balancer)
            // Format: "client, proxy1, proxy2"
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // Take the first IP (the client)
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($ips[0]);

                // Validate it's a valid IP
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }

            // Check X-Real-IP header (alternative proxy header)
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $clientIp = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }

            // Fallback to REMOTE_ADDR (direct connection)
            if (!empty($_SERVER['REMOTE_ADDR'])) {
                $clientIp = $_SERVER['REMOTE_ADDR'];
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }

            return null;
        }

        // Use provided headers array (normalize keys to lowercase)
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = is_array($value) ? $value[0] : $value;
        }

        // Check X-Forwarded-For
        if (!empty($normalizedHeaders['x-forwarded-for'])) {
            $ips = explode(',', $normalizedHeaders['x-forwarded-for']);
            $clientIp = trim($ips[0]);
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        // Check X-Real-IP
        if (!empty($normalizedHeaders['x-real-ip'])) {
            $clientIp = trim($normalizedHeaders['x-real-ip']);
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        // Fall back to REMOTE_ADDR from $_SERVER if still available
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $clientIp = $_SERVER['REMOTE_ADDR'];
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        return null;
    }

    /**
     * Detect if TraceKit Local UI is running at localhost:9999
     */
    private function detectLocalUI(): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 1,
                    'ignore_errors' => true,
                ]
            ]);

            $response = @file_get_contents('http://localhost:9999/api/health', false, $context);

            if ($response !== false) {
                error_log('🔍 Local UI detected at http://localhost:9999');
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Suppress OpenTelemetry internal error output (export failures, etc.)
     * This prevents noisy error messages when running without a valid API key
     */
    private function suppressOpenTelemetryErrors(): void
    {
        // Set environment variable to disable OpenTelemetry error logging
        putenv('OTEL_PHP_LOG_DESTINATION=none');

        // Also register a custom error handler that filters out OpenTelemetry errors
        $previousHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$previousHandler) {
            // Suppress OpenTelemetry-related errors
            if (str_contains($errfile, 'open-telemetry') || str_contains($errstr, 'OpenTelemetry')) {
                return true; // Suppress the error
            }

            // Call previous handler for other errors
            if ($previousHandler) {
                return $previousHandler($errno, $errstr, $errfile, $errline);
            }

            return false; // Let PHP handle it
        });
    }

    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                $normalized[$key] = array_map('strval', $value);
            } else {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * Resolve endpoint URL from base endpoint and path
     */
    private function resolveEndpoint(string $endpoint, string $path, bool $useSSL = true): string
    {
        // If endpoint has a scheme
        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            $endpoint = rtrim($endpoint, '/');
            $trimmed = preg_replace('#^https?://#', '', $endpoint);

            // If endpoint has a path component
            if (str_contains($trimmed, '/')) {
                // Always extract base URL and append correct path
                $base = $this->extractBaseURL($endpoint);
                if ($path === '') {
                    return $base;
                }
                return $base . $path;
            }

            // Just host with scheme, add the path
            return $endpoint . $path;
        }

        // No scheme - build URL with scheme
        $scheme = $useSSL ? 'https://' : 'http://';
        $endpoint = rtrim($endpoint, '/');
        return $scheme . $endpoint . $path;
    }

    /**
     * Extract base URL (scheme + host) from full URL
     */
    private function extractBaseURL(string $fullURL): string
    {
        // Check if URL contains known service-specific paths
        $hasServicePath = str_contains($fullURL, '/v1/traces') ||
                          str_contains($fullURL, '/v1/metrics') ||
                          str_contains($fullURL, '/api/v1/traces') ||
                          str_contains($fullURL, '/api/v1/metrics');

        // If it doesn't have a service-specific path, keep the URL as-is
        if (!$hasServicePath) {
            return $fullURL;
        }

        $parsed = parse_url($fullURL);
        $base = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }
        return $base;
    }

    /**
     * Get or create a counter metric
     */
    public function counter(string $name, array $tags = []): Counter
    {
        return $this->metricsRegistry->counter($name, $tags);
    }

    /**
     * Get or create a gauge metric
     */
    public function gauge(string $name, array $tags = []): Gauge
    {
        return $this->metricsRegistry->gauge($name, $tags);
    }

    /**
     * Get or create a histogram metric
     */
    public function histogram(string $name, array $tags = []): Histogram
    {
        return $this->metricsRegistry->histogram($name, $tags);
    }

    /**
     * Shutdown the client and flush all pending data
     */
    public function shutdown(): void
    {
        if ($this->snapshotClient) {
            $this->snapshotClient->stop();
        }

        if ($this->metricsRegistry) {
            $this->metricsRegistry->shutdown();
        }

        $this->tracerProvider->shutdown();
    }
}
