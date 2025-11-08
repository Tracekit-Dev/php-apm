<?php

namespace TraceKit\PHP;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

class TracekitClient
{
    private TracerProviderInterface $tracerProvider;
    private TracerInterface $tracer;
    private string $endpoint;
    private string $apiKey;
    private string $serviceName;
    private bool $enabled;
    private float $sampleRate;

    public function __construct(array $config = [])
    {
        $this->endpoint = $config['endpoint'] ?? 'https://app.tracekit.dev/v1/traces';
        $this->apiKey = $config['api_key'] ?? '';
        $this->serviceName = $config['service_name'] ?? 'php-app';
        $this->enabled = $config['enabled'] ?? true;
        $this->sampleRate = $config['sample_rate'] ?? 1.0;

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
        $scope->detach();
    }

    public function addEvent(array $spanData, string $name, array $attributes = []): void
    {
        $spanData['span']->addEvent($name, $this->normalizeAttributes($attributes));
    }

    public function recordException(array $spanData, \Throwable $exception): void
    {
        $span = $spanData['span'];
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
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
