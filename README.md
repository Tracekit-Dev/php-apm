# TraceKit APM for PHP

Framework-agnostic distributed tracing and performance monitoring for any PHP application.

[![Packagist Version](https://img.shields.io/packagist/v/tracekit/php-apm.svg)](https://packagist.org/packages/tracekit/php-apm)
[![Downloads](https://img.shields.io/packagist/dm/tracekit/php-apm.svg)](https://packagist.org/packages/tracekit/php-apm)
[![License](https://img.shields.io/packagist/l/tracekit/php-apm.svg)](https://packagist.org/packages/tracekit/php-apm)

## Features

- **Framework Agnostic** - Works with any PHP application (vanilla PHP, Symfony, Slim, etc.)
- **OpenTelemetry Standard** - Built on OpenTelemetry for industry-standard tracing
- **Automatic Context Propagation** - Child spans automatically inherit from parent
- **Manual Instrumentation** - Full control over what and how you trace
- **HTTP Request Tracing** - Track requests, database queries, and external API calls
- **Error Tracking** - Capture exceptions with full context
- **Code Monitoring** - Live debugging with breakpoints and variable inspection
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

// Start a trace (returns array with span and scope)
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

// Important: flush traces before exit
$tracekit->flush();
```

## Code Monitoring (Live Debugging)

TraceKit includes production-safe code monitoring for live debugging without redeployment.

### Enable Code Monitoring

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

// Enable code monitoring
$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-php-app',
    'endpoint' => 'https://app.tracekit.dev/v1/traces',
    'code_monitoring_enabled' => true,
    'code_monitoring_max_depth' => 3,      // Nested array/object depth
    'code_monitoring_max_string' => 1000,  // Truncate long strings
]);
```

### Add Debug Points

Add checkpoints anywhere in your code to capture variable state and stack traces:

```php
<?php

class CheckoutService
{
    private $tracekit;

    public function __construct($tracekit)
    {
        $this->tracekit = $tracekit;
    }

    public function processPayment($userId, $cart)
    {
        // Automatic snapshot capture with label
        $this->tracekit->captureSnapshot('checkout-validation', [
            'user_id' => $userId,
            'cart_items' => count($cart['items'] ?? []),
            'total_amount' => $cart['total'] ?? 0,
        ]);

        try {
            $result = $this->chargeCard($cart['total'], $userId);

            // Another checkpoint
            $this->tracekit->captureSnapshot('payment-success', [
                'user_id' => $userId,
                'payment_id' => $result['payment_id'],
                'amount' => $result['amount'],
            ]);

            return $result;

        } catch (Exception $e) {
            // Automatic error capture
            $this->tracekit->captureSnapshot('payment-error', [
                'user_id' => $userId,
                'amount' => $cart['total'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function chargeCard($amount, $userId)
    {
        // Simulate payment processing
        if ($amount > 1000) {
            throw new Exception('Amount exceeds limit');
        }

        return [
            'payment_id' => 'pay_' . uniqid(),
            'amount' => $amount,
            'status' => 'succeeded',
        ];
    }
}

// Usage
$checkout = new CheckoutService($tracekit);
$result = $checkout->processPayment(123, ['total' => 99.99, 'items' => ['item1']]);
```

### Manual Breakpoint Polling

Since PHP doesn't have built-in background task scheduling, you need to poll for breakpoints manually:

```php
// Option 1: Poll on every Nth request
if (rand(1, 100) <= 5) { // 5% of requests
    $tracekit->pollBreakpoints();
}

// Option 2: Use a cron job
// */1 * * * * php /path/to/poll-breakpoints.php

// poll-breakpoints.php
require 'vendor/autoload.php';
$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-php-app',
    'code_monitoring_enabled' => true,
]);
$tracekit->pollBreakpoints();
```

### Automatic Breakpoint Management

- **Auto-Registration**: First call to `captureSnapshot()` automatically creates breakpoints in TraceKit
- **Smart Matching**: Breakpoints match by function name + label (stable across code changes)
- **Manual Polling**: You must call `pollBreakpoints()` periodically to fetch active breakpoints
- **Production Safe**: No performance impact when breakpoints are inactive

### What Gets Captured

Snapshots include:
- **Variables**: Local variables at capture point
- **Stack Trace**: Full call stack with file/line numbers
- **Request Context**: HTTP method, URL, headers, query params (when available)
- **Execution Time**: When the snapshot was captured

### Framework Integration Examples

#### Slim Framework
```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'slim-app',
    'code_monitoring_enabled' => true,
]);

$app->post('/checkout', function ($request, $response) use ($tracekit) {
    $data = $request->getParsedBody();

    // Poll breakpoints occasionally
    if (rand(1, 20) === 1) { // 5% chance
        $tracekit->pollBreakpoints();
    }

    // Capture snapshot
    $tracekit->captureSnapshot('checkout-start', [
        'user_id' => $data['user_id'],
        'amount' => $data['amount'],
    ]);

    // Process payment...
    $result = ['payment_id' => 'pay_' . uniqid()];

    return $response->withJson($result);
});

$app->run();
```

#### Symfony Controller
```php
<?php

namespace App\Controller;

use TraceKit\PHP\TracekitClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class PaymentController
{
    private $tracekit;

    public function __construct()
    {
        $this->tracekit = new TracekitClient([
            'api_key' => getenv('TRACEKIT_API_KEY'),
            'service_name' => 'symfony-app',
            'code_monitoring_enabled' => true,
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        // Poll occasionally (you could also use a cron job)
        if (rand(1, 20) === 1) {
            $this->tracekit->pollBreakpoints();
        }

        $data = json_decode($request->getContent(), true);

        $this->tracekit->captureSnapshot('checkout-validation', [
            'user_id' => $data['user_id'],
            'cart_total' => $data['cart']['total'],
        ]);

        // Process payment...
        $result = $this->processPayment($data);

        $this->tracekit->captureSnapshot('checkout-complete', [
            'user_id' => $data['user_id'],
            'payment_id' => $result['payment_id'],
        ]);

        return new JsonResponse($result);
    }

    private function processPayment(array $data): array
    {
        // Payment logic here...
        return ['payment_id' => 'pay_' . uniqid()];
    }
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

    // Optional: Enable live code debugging (default: false)
    'code_monitoring_enabled' => true,
    'code_monitoring_max_depth' => 3,      // Nested array/object depth
    'code_monitoring_max_string' => 1000,  // Truncate long strings
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
    // Child span automatically links to active parent via context
    $span = $tracekit->startSpan('db.query.users', [
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
    $span = $tracekit->startSpan('http.client.get', [
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

### Nested Spans (Automatic Context Propagation)

```php
<?php

function processOrder($tracekit, $orderId) {
    // Parent span
    $orderSpan = $tracekit->startSpan('process-order', [
        'order.id' => $orderId,
    ]);

    try {
        // Child spans automatically link to orderSpan via context

        // Validate order
        $validationSpan = $tracekit->startSpan('validate-order', [
            'order.id' => $orderId,
        ]);
        $valid = validateOrder($orderId);
        $tracekit->endSpan($validationSpan, ['valid' => $valid]);

        if (!$valid) {
            throw new \Exception('Invalid order');
        }

        // Process payment
        $paymentSpan = $tracekit->startSpan('process-payment', [
            'order.id' => $orderId,
        ]);
        $paymentResult = processPayment($orderId);
        $tracekit->endSpan($paymentSpan, ['payment.status' => $paymentResult]);

        // Ship order
        $shippingSpan = $tracekit->startSpan('ship-order', [
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

## How Context Propagation Works

TraceKit uses OpenTelemetry's Context API to automatically manage span relationships:

1. **Root Span**: `startTrace()` creates a root span and activates it in the context
2. **Child Spans**: `startSpan()` automatically inherits from the currently active span
3. **Scope Management**: Each span has a scope that's detached when `endSpan()` is called
4. **Automatic Hierarchy**: All spans within the same request share the same trace ID

```php
// Root span (activated in context)
$rootSpan = $tracekit->startTrace('http-request');

    // Child 1 (inherits from root automatically)
    $child1 = $tracekit->startSpan('database-query');
    $tracekit->endSpan($child1);  // Detaches child1, root becomes active again

    // Child 2 (also inherits from root)
    $child2 = $tracekit->startSpan('api-call');

        // Grandchild (inherits from child2)
        $grandchild = $tracekit->startSpan('process-data');
        $tracekit->endSpan($grandchild);  // Detaches grandchild, child2 active

    $tracekit->endSpan($child2);  // Detaches child2, root active

$tracekit->endSpan($rootSpan);  // Detaches root
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

#### `startTrace(string $operationName, array $attributes = []): array`

Start a new root trace span (server request). Returns an array with the span and scope.

**Returns:** `['span' => SpanInterface, 'scope' => ScopeInterface]`

#### `startSpan(string $operationName, array $attributes = []): array`

Start a new child span. Automatically inherits from the currently active span via context.

**Returns:** `['span' => SpanInterface, 'scope' => ScopeInterface]`

#### `endSpan(array $spanData, array $finalAttributes = [], ?string $status = 'OK'): void`

End a span and detach its scope from the context.

**Parameters:**
- `$spanData` - Array returned from `startTrace()` or `startSpan()`
- `$finalAttributes` - Optional attributes to add before ending
- `$status` - Span status: `'OK'` or `'ERROR'`

#### `recordException(array $spanData, \Throwable $exception): void`

Record an exception on a span.

#### `addEvent(array $spanData, string $name, array $attributes = []): void`

Add an event to a span.

#### `flush(): void`

Force flush all pending spans to the backend.

#### `shutdown(): void`

Shutdown the tracer provider.

## Performance

TraceKit APM is designed to have minimal performance impact:

- **< 5% overhead** on average request time
- Asynchronous trace sending
- Configurable sampling for high-traffic applications
- Efficient context propagation

## Requirements

- PHP 8.1 or higher
- Composer
- PSR-18 HTTP Client (e.g., Guzzle)

## Support

- Documentation: [https://app.tracekit.dev/docs](https://app.tracekit.dev/docs)
- Issues: [https://github.com/Tracekit-Dev/php-apm/issues](https://github.com/Tracekit-Dev/php-apm/issues)
- Email: support@tracekit.dev

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Built with ❤️ by the TraceKit team using OpenTelemetry.
