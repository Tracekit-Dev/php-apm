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
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

use TraceKit\PHP\SnapshotClient;
use TraceKit\PHP\Instrumentation\HttpClientInstrumentation;

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
    private TraceContextPropagator $propagator;

    public function __construct(array $config = [])
    {
        $this->endpoint = $config['endpoint'] ?? 'https://app.tracekit.dev/v1/traces';
        $this->apiKey = $config['api_key'] ?? '';
        $this->serviceName = $config['service_name'] ?? 'php-app';
        $this->enabled = $config['enabled'] ?? true;
        $this->sampleRate = $config['sample_rate'] ?? 1.0;
        $this->serviceNameMappings = $config['service_name_mappings'] ?? [];
        $this->propagator = TraceContextPropagator::getInstance();

        // Suppress OpenTelemetry error output (export failures, etc.) in development
        // Set 'suppress_errors' => false in production to see export errors
        if ($config['suppress_errors'] ?? true) {
            $this->suppressOpenTelemetryErrors();
        }

        // Initialize code monitoring if enabled
        if (($config['code_monitoring_enabled'] ?? false) && $this->apiKey) {
            // Extract base URL from endpoint (remove /v1/traces path for SDK endpoints)
            $parsedUrl = parse_url($this->endpoint);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }

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
     *
     * @return array ['span' => SpanInterface, 'scope' => int] Scope token for cleanup
     */
    public function startTrace(string $operationName, array $attributes = []): array
    {
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
     *
     * @return array ['span' => SpanInterface, 'scope' => int] Scope token for cleanup
     */
    public function startServerSpan(
        string $operationName,
        array $attributes = [],
        ?ContextInterface $parentContext = null
    ): array {
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

    public function shutdown(): void
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            $this->tracerProvider->shutdown();
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
}
