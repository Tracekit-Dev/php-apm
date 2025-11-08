# TraceKit APM for PHP

Framework-agnostic distributed tracing and performance monitoring for any PHP application.

[![Packagist Version](https://img.shields.io/packagist/v/tracekit/php-apm.svg)](https://packagist.org/packages/tracekit/php-apm)
[![Downloads](https://img.shields.io/packagist/dm/tracekit/php-apm.svg)](https://packagist.org/packages/tracekit/php-apm)
[![License](https://img.shields.io/packagist/l/tracekit/php-apm.svg)](https://packagist.org/packages/tracekit/php-apm)

## Features

- **Framework Agnostic** - Works with any PHP application (vanilla PHP, Symfony, Slim, etc.)
- **OpenTelemetry Standard** - Built on OpenTelemetry for industry-standard tracing
- **Manual Instrumentation** - Full control over what and how you trace
- **HTTP Request Tracing** - Track requests, database queries, and external API calls
- **Error Tracking** - Capture exceptions with full context
- **Low Overhead** - Minimal performance impact

## Installation

```bash
composer require tracekit/php-apm
```

## Quick Start

### Basic Usage

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

// Initialize TraceKit
$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-php-app',
    'endpoint' => 'https://app.tracekit.dev/v1/traces',
]);

// Start a trace
$span = $tracekit->startTrace('process-request', [
    'http.method' => $_SERVER['REQUEST_METHOD'],
    'http.url' => $_SERVER['REQUEST_URI'],
]);

try {
    // Your application logic here
    processRequest();

    $tracekit->endSpan($span, [
        'http.status_code' => 200,
    ]);
} catch (\Exception $e) {
    $tracekit->recordException($span, $e);
    $tracekit->endSpan($span, [], 'ERROR');
    throw $e;
}
```

## Configuration

### Basic Configuration

```php
$tracekit = new TracekitClient([
    // Required: Your TraceKit API key
    'api_key' => getenv('TRACEKIT_API_KEY'),

    // Optional: Service name (default: 'php-app')
    'service_name' => 'my-service',

    // Optional: TraceKit endpoint (default: 'https://app.tracekit.dev/v1/traces')
    'endpoint' => 'https://app.tracekit.dev/v1/traces',

    // Optional: Enable/disable tracing (default: true)
    'enabled' => getenv('APP_ENV') === 'production',

    // Optional: Sample rate 0.0-1.0 (default: 1.0 = 100%)
    'sample_rate' => 0.5, // Trace 50% of requests
]);
```

### Environment Variables

Create a `.env` file or set these environment variables:

```bash
TRACEKIT_API_KEY=ctxio_your_generated_api_key_here
TRACEKIT_ENDPOINT=https://app.tracekit.dev/v1/traces
TRACEKIT_SERVICE_NAME=my-php-app
```

## Usage Examples

### HTTP Request Tracing

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'api-server',
]);

// Start tracing the request
$requestSpan = $tracekit->startTrace('http-request', [
    'http.method' => $_SERVER['REQUEST_METHOD'],
    'http.url' => $_SERVER['REQUEST_URI'],
    'http.user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

try {
    // Route the request
    $result = handleRequest($_SERVER['REQUEST_URI']);

    $tracekit->endSpan($requestSpan, [
        'http.status_code' => 200,
    ]);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($result);

} catch (\Exception $e) {
    $tracekit->recordException($requestSpan, $e);
    $tracekit->endSpan($requestSpan, [
        'http.status_code' => 500,
    ], 'ERROR');

    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$tracekit->flush();
```

### Database Query Tracing

```php
<?php

function getUserById($tracekit, $userId) {
    $span = $tracekit->startSpan('db.query.users', null, [
        'db.system' => 'mysql',
        'db.operation' => 'SELECT',
        'user.id' => $userId,
    ]);

    try {
        $pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $tracekit->endSpan($span, [
            'db.rows_affected' => $stmt->rowCount(),
        ]);

        return $user;
    } catch (\PDOException $e) {
        $tracekit->recordException($span, $e);
        $tracekit->endSpan($span, [], 'ERROR');
        throw $e;
    }
}
```

### External API Call Tracing

```php
<?php

function fetchExternalData($tracekit, $url) {
    $span = $tracekit->startSpan('http.client.get', null, [
        'http.url' => $url,
        'http.method' => 'GET',
    ]);

    try {
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        $tracekit->endSpan($span, [
            'http.status_code' => 200,
            'response.size' => strlen($response),
        ]);

        return $data;
    } catch (\Exception $e) {
        $tracekit->recordException($span, $e);
        $tracekit->endSpan($span, [], 'ERROR');
        throw $e;
    }
}
```

### Nested Spans

```php
<?php

function processOrder($tracekit, $orderId) {
    $orderSpan = $tracekit->startSpan('process-order', null, [
        'order.id' => $orderId,
    ]);

    try {
        // Validate order
        $validationSpan = $tracekit->startSpan('validate-order', $orderSpan, [
            'order.id' => $orderId,
        ]);
        $valid = validateOrder($orderId);
        $tracekit->endSpan($validationSpan, ['valid' => $valid]);

        if (!$valid) {
            throw new \Exception('Invalid order');
        }

        // Process payment
        $paymentSpan = $tracekit->startSpan('process-payment', $orderSpan, [
            'order.id' => $orderId,
        ]);
        $paymentResult = processPayment($orderId);
        $tracekit->endSpan($paymentSpan, ['payment.status' => $paymentResult]);

        // Ship order
        $shippingSpan = $tracekit->startSpan('ship-order', $orderSpan, [
            'order.id' => $orderId,
        ]);
        $trackingId = shipOrder($orderId);
        $tracekit->endSpan($shippingSpan, ['tracking.id' => $trackingId]);

        $tracekit->endSpan($orderSpan, [
            'order.status' => 'completed',
        ]);

        return true;
    } catch (\Exception $e) {
        $tracekit->recordException($orderSpan, $e);
        $tracekit->endSpan($orderSpan, [], 'ERROR');
        throw $e;
    }
}
```

## Framework Integration

### Vanilla PHP

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-app',
]);

$span = $tracekit->startTrace('http-request', [
    'http.method' => $_SERVER['REQUEST_METHOD'],
    'http.url' => $_SERVER['REQUEST_URI'],
]);

// Your application logic
echo "Hello World!";

$tracekit->endSpan($span);
$tracekit->flush();
```

### Symfony

```php
<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use TraceKit\PHP\TracekitClient;

class TracekitListener
{
    private TracekitClient $tracekit;
    private $currentSpan;

    public function __construct()
    {
        $this->tracekit = new TracekitClient([
            'api_key' => $_ENV['TRACEKIT_API_KEY'],
            'service_name' => 'symfony-app',
        ]);
    }

    public function onKernelRequest(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $this->currentSpan = $this->tracekit->startTrace('http-request', [
            'http.method' => $request->getMethod(),
            'http.url' => $request->getRequestUri(),
            'http.route' => $request->attributes->get('_route'),
        ]);
    }

    public function onKernelResponse(ResponseEvent $event)
    {
        if (!$event->isMainRequest() || !$this->currentSpan) {
            return;
        }

        $this->tracekit->endSpan($this->currentSpan, [
            'http.status_code' => $event->getResponse()->getStatusCode(),
        ]);
        $this->tracekit->flush();
    }

    public function onKernelException(ExceptionEvent $event)
    {
        if ($this->currentSpan) {
            $this->tracekit->recordException($this->currentSpan, $event->getThrowable());
            $this->tracekit->endSpan($this->currentSpan, [], 'ERROR');
            $this->tracekit->flush();
        }
    }
}
```

### Slim Framework

```php
<?php

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use TraceKit\PHP\TracekitClient;

require 'vendor/autoload.php';

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'slim-app',
]);

$app = AppFactory::create();

// Tracing middleware
$app->add(function (Request $request, $handler) use ($tracekit) {
    $span = $tracekit->startTrace('http-request', [
        'http.method' => $request->getMethod(),
        'http.url' => (string) $request->getUri(),
    ]);

    try {
        $response = $handler->handle($request);

        $tracekit->endSpan($span, [
            'http.status_code' => $response->getStatusCode(),
        ]);

        $tracekit->flush();
        return $response;
    } catch (\Exception $e) {
        $tracekit->recordException($span, $e);
        $tracekit->endSpan($span, [], 'ERROR');
        $tracekit->flush();
        throw $e;
    }
});

$app->get('/hello/{name}', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello, " . $args['name']);
    return $response;
});

$app->run();
```

## API Reference

### TracekitClient

#### `__construct(array $config)`

Initialize the TraceKit client.

**Parameters:**
- `api_key` (string, required) - Your TraceKit API key
- `service_name` (string, optional) - Service name (default: 'php-app')
- `endpoint` (string, optional) - TraceKit endpoint URL
- `enabled` (bool, optional) - Enable/disable tracing (default: true)
- `sample_rate` (float, optional) - Sample rate 0.0-1.0 (default: 1.0)

#### `startTrace(string $operationName, array $attributes = []): SpanInterface`

Start a new root trace span (server request).

#### `startSpan(string $operationName, ?SpanInterface $parentSpan = null, array $attributes = []): SpanInterface`

Start a new child span.

#### `endSpan(SpanInterface $span, array $finalAttributes = [], ?string $status = 'OK'): void`

End a span with optional final attributes and status.

**Status values:** `'OK'`, `'ERROR'`

#### `recordException(SpanInterface $span, \Throwable $exception): void`

Record an exception on a span.

#### `addEvent(SpanInterface $span, string $name, array $attributes = []): void`

Add an event to a span.

#### `flush(): void`

Force flush all pending spans.

#### `shutdown(): void`

Shutdown the tracer provider.

## Performance

TraceKit APM is designed to have minimal performance impact:

- **< 5% overhead** on average request time
- Asynchronous trace sending
- Configurable sampling for high-traffic applications

## Requirements

- PHP 8.1 or higher
- Composer

## Support

- Documentation: [https://app.tracekit.dev/docs](https://app.tracekit.dev/docs)
- Issues: [https://github.com/Tracekit-Dev/php-apm/issues](https://github.com/Tracekit-Dev/php-apm/issues)
- Email: support@tracekit.dev

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Built with ❤️ by the TraceKit team using OpenTelemetry.
