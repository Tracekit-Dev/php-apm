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
- **Client IP Capture** - Automatic IP detection for DDoS & traffic analysis
- **Error Tracking** - Capture exceptions with full context
- **Code Monitoring** - Live debugging with breakpoints and variable inspection
- **Metrics API** - Counter, Gauge, and Histogram metrics with automatic OTLP export
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
    'http.client_ip' => TracekitClient::extractClientIp(),  // Automatic IP detection
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

## Local Development

Debug your PHP application locally without creating a cloud account using TraceKit Local UI.

### Quick Start

```bash
# Install Local UI globally
npm install -g @tracekit/local-ui

# Start it
tracekit-local
```

The Local UI will start at `http://localhost:9999` and automatically open in your browser.

### How It Works

When running in development mode (`APP_ENV=local` or `APP_ENV=development`), the SDK automatically:

1. Detects if Local UI is running at `http://localhost:9999`
2. Sends traces to both Local UI and cloud (if API key is present)
3. Falls back gracefully if Local UI is not available

**No code changes needed!** Just set the environment variable:

```bash
export APP_ENV=development
export TRACEKIT_API_KEY=your-key  # Optional - works without it!
php app.php
```

You'll see traces appear in real-time at `http://localhost:9999`.

### Features

- Real-time trace viewing in your browser
- Works completely offline
- No cloud account required
- Zero configuration
- Automatic cleanup (1000 traces max, 1 hour retention)

### Local-Only Development

To use Local UI without cloud sending:

```bash
# Don't set TRACEKIT_API_KEY
export APP_ENV=development
php app.php
```

Traces will only go to Local UI.

### Disabling Local UI

To disable automatic Local UI detection:

```bash
export APP_ENV=production
# or don't run Local UI
```

### Learn More

- GitHub: [https://github.com/Tracekit-Dev/local-debug-ui](https://github.com/Tracekit-Dev/local-debug-ui)
- npm: [@tracekit/local-ui](https://www.npmjs.com/package/@tracekit/local-ui)

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

## Metrics

TraceKit APM includes a powerful metrics API for tracking application performance and business metrics with automatic OTLP export.

### Metric Types

- **Counter**: Monotonically increasing values (requests, errors, events)
- **Gauge**: Point-in-time values that can go up or down (active connections, queue size, memory usage)
- **Histogram**: Value distributions (request duration, payload sizes)

### Basic Usage

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-app',
]);

// Create metrics
$requestCounter = $tracekit->counter('http.requests.total', [
    'service' => 'my-app'
]);

$activeRequestsGauge = $tracekit->gauge('http.requests.active', [
    'service' => 'my-app'
]);

$requestDurationHistogram = $tracekit->histogram('http.request.duration', [
    'unit' => 'ms'
]);
```

### Counter

Counters track values that only increase (never decrease).

```php
<?php

// Create a counter
$requestCounter = $tracekit->counter('http.requests.total', [
    'service' => 'my-app',
    'environment' => 'production'
]);

// Increment by 1
$requestCounter->inc();

// Add a specific value
$requestCounter->add(5.0);
```

**Common Use Cases:**
- Request count
- Error count
- Cache hits/misses
- Items processed

### Gauge

Gauges track values that can go up or down.

```php
<?php

// Create a gauge
$activeRequestsGauge = $tracekit->gauge('http.requests.active', [
    'service' => 'my-app'
]);

// Set to specific value
$activeRequestsGauge->set(42.0);

// Increment by 1
$activeRequestsGauge->inc();

// Decrement by 1
$activeRequestsGauge->dec();
```

**Common Use Cases:**
- Active requests
- Queue size
- Memory usage
- Active connections

### Histogram

Histograms track the distribution of values.

```php
<?php

// Create a histogram
$requestDurationHistogram = $tracekit->histogram('http.request.duration', [
    'service' => 'my-app',
    'unit' => 'ms'
]);

// Record values
$requestDurationHistogram->record(45.2);  // 45.2ms
$requestDurationHistogram->record(123.8); // 123.8ms
```

**Common Use Cases:**
- Request duration
- Response size
- Query execution time
- Processing time

### Complete Example: HTTP Request Metrics

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'php-api',
]);

// Initialize metrics
$requestCounter = $tracekit->counter('http.requests.total', [
    'service' => 'php-api'
]);
$activeRequestsGauge = $tracekit->gauge('http.requests.active', [
    'service' => 'php-api'
]);
$requestDurationHistogram = $tracekit->histogram('http.request.duration', [
    'unit' => 'ms'
]);
$errorCounter = $tracekit->counter('http.errors.total', [
    'service' => 'php-api'
]);

// Track request start
$startTime = microtime(true);
$activeRequestsGauge->inc();

try {
    // Your application logic
    $result = processRequest();

    http_response_code(200);
    echo json_encode($result);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);

    // Track errors
    $errorCounter->inc();
}

// Track metrics at request end
$requestCounter->inc();
$activeRequestsGauge->dec();
$duration = (microtime(true) - $startTime) * 1000; // Convert to ms
$requestDurationHistogram->record($duration);

// Track error status codes
$statusCode = http_response_code();
if ($statusCode >= 400) {
    $errorCounter->inc();
}

// Flush all data
$tracekit->shutdown();
```

### Framework Examples

#### Vanilla PHP Middleware

```php
<?php

class MetricsMiddleware
{
    private $tracekit;
    private $requestCounter;
    private $activeRequestsGauge;
    private $requestDurationHistogram;
    private $errorCounter;

    public function __construct($tracekit)
    {
        $this->tracekit = $tracekit;

        // Initialize metrics once
        $this->requestCounter = $tracekit->counter('http.requests.total', [
            'service' => 'my-app'
        ]);
        $this->activeRequestsGauge = $tracekit->gauge('http.requests.active', [
            'service' => 'my-app'
        ]);
        $this->requestDurationHistogram = $tracekit->histogram('http.request.duration', [
            'unit' => 'ms'
        ]);
        $this->errorCounter = $tracekit->counter('http.errors.total', [
            'service' => 'my-app'
        ]);
    }

    public function handle(callable $next)
    {
        $startTime = microtime(true);
        $this->activeRequestsGauge->inc();

        try {
            $response = $next();
            return $response;
        } finally {
            // Track metrics
            $this->requestCounter->inc();
            $this->activeRequestsGauge->dec();

            $duration = (microtime(true) - $startTime) * 1000;
            $this->requestDurationHistogram->record($duration);

            if (http_response_code() >= 400) {
                $this->errorCounter->inc();
            }
        }
    }
}

// Usage
$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-app',
]);

$metrics = new MetricsMiddleware($tracekit);
$metrics->handle(function() {
    // Your application logic
    echo "Hello World!";
});

$tracekit->shutdown();
```

#### Slim Framework

```php
<?php

use Slim\Factory\AppFactory;
use TraceKit\PHP\TracekitClient;

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'slim-app',
]);

// Initialize metrics
$requestCounter = $tracekit->counter('http.requests.total', ['service' => 'slim-app']);
$activeRequestsGauge = $tracekit->gauge('http.requests.active', ['service' => 'slim-app']);
$requestDurationHistogram = $tracekit->histogram('http.request.duration', ['unit' => 'ms']);
$errorCounter = $tracekit->counter('http.errors.total', ['service' => 'slim-app']);

$app = AppFactory::create();

// Metrics middleware
$app->add(function ($request, $handler) use (
    $tracekit,
    $requestCounter,
    $activeRequestsGauge,
    $requestDurationHistogram,
    $errorCounter
) {
    $startTime = microtime(true);
    $activeRequestsGauge->inc();

    try {
        $response = $handler->handle($request);

        // Track metrics
        $requestCounter->inc();
        $activeRequestsGauge->dec();

        $duration = (microtime(true) - $startTime) * 1000;
        $requestDurationHistogram->record($duration);

        if ($response->getStatusCode() >= 400) {
            $errorCounter->inc();
        }

        return $response;
    } catch (\Exception $e) {
        $errorCounter->inc();
        throw $e;
    }
});

$app->get('/hello', function ($request, $response) {
    $response->getBody()->write("Hello World!");
    return $response;
});

$app->run();

// Shutdown to flush metrics
$tracekit->shutdown();
```

### Tags for Dimensional Analysis

Add tags to metrics for filtering and grouping:

```php
<?php

// Metrics with tags
$requestCounter = $tracekit->counter('http.requests.total', [
    'service' => 'my-app',
    'environment' => 'production',
    'region' => 'us-east-1'
]);

$errorCounter = $tracekit->counter('http.errors.total', [
    'service' => 'my-app',
    'error_type' => '4xx'
]);

$cacheCounter = $tracekit->counter('cache.hits', [
    'service' => 'my-app',
    'cache_type' => 'redis'
]);
```

### Common Use Cases

#### Database Metrics

```php
<?php

$dbQueryCounter = $tracekit->counter('db.queries.total', [
    'service' => 'my-app',
    'db' => 'mysql'
]);

$dbConnectionsGauge = $tracekit->gauge('db.connections.active', [
    'service' => 'my-app',
    'db' => 'mysql'
]);

$dbQueryDuration = $tracekit->histogram('db.query.duration', [
    'service' => 'my-app',
    'unit' => 'ms'
]);

// Track a query
$dbQueryCounter->inc();
$dbConnectionsGauge->inc();

$startTime = microtime(true);
$result = $pdo->query("SELECT * FROM users");
$duration = (microtime(true) - $startTime) * 1000;

$dbQueryDuration->record($duration);
$dbConnectionsGauge->dec();
```

#### Business Metrics

```php
<?php

$checkoutCounter = $tracekit->counter('business.checkouts.total', [
    'service' => 'checkout-service'
]);

$revenueGauge = $tracekit->gauge('business.revenue.total', [
    'service' => 'checkout-service',
    'currency' => 'USD'
]);

$orderValueHistogram = $tracekit->histogram('business.order.value', [
    'service' => 'checkout-service',
    'currency' => 'USD'
]);

// Track a successful checkout
$checkoutCounter->inc();
$revenueGauge->set($totalRevenue);
$orderValueHistogram->record($orderAmount);
```

### Metric Export

Metrics are automatically buffered and exported to TraceKit:

- **Buffer size**: 100 metrics
- **Flush interval**: 10 seconds
- **Endpoint**: Automatically resolved to `/v1/metrics`
- **Format**: OpenTelemetry Protocol (OTLP)

Metrics are automatically sent when:
1. Buffer reaches 100 metrics
2. 10 seconds have elapsed since last export
3. `shutdown()` is called

```php
<?php

// Explicit flush of all pending data (traces + metrics)
$tracekit->shutdown();

// At the end of your script
register_shutdown_function(function() use ($tracekit) {
    $tracekit->shutdown();
});
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

    // Optional: Map hostnames to service names for service graph
    'service_name_mappings' => [
        'localhost:8082' => 'payment-service',
        'localhost:8083' => 'user-service',
    ],
]);
```

### Environment Variables

Create a `.env` file or set these environment variables:

```bash
TRACEKIT_API_KEY=ctxio_your_generated_api_key_here
TRACEKIT_ENDPOINT=https://app.tracekit.dev/v1/traces
TRACEKIT_SERVICE_NAME=my-php-app
```

## Automatic HTTP Client Instrumentation

TraceKit provides instrumentation for outgoing HTTP calls to create service dependency graphs.

### How It Works

When your service makes an HTTP request:

1. ✅ TraceKit creates a **CLIENT span** for the outgoing request
2. ✅ Trace context is injected into request headers (`traceparent`)
3. ✅ `peer.service` attribute is set based on the target hostname
4. ✅ The receiving service creates a **SERVER span** linked to your CLIENT span
5. ✅ TraceKit maps the dependency: **YourService → TargetService**

### Supported HTTP Clients

#### cURL (with wrapper)

```php
$ch = curl_init("http://payment-service/charge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['amount' => 99.99]));

// Wrap curl_exec with TraceKit instrumentation
$instrumentation = $tracekit->getHttpClientInstrumentation();
$result = $instrumentation->wrapCurlExec($ch);

curl_close($ch);
```

**What This Does:**
- Creates a CLIENT span for the cURL request
- Sets `peer.service = "payment-service"`
- Injects `traceparent` header for distributed tracing
- Records HTTP status code and errors

#### Guzzle (with middleware)

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

// Create Guzzle client with TraceKit middleware
$stack = HandlerStack::create();
$stack->push($tracekit->getHttpClientInstrumentation()->getGuzzleMiddleware());

$client = new Client(['handler' => $stack]);

// All Guzzle requests now automatically create CLIENT spans!
$response = $client->post('http://payment-service/charge', [
    'json' => ['amount' => 99.99],
]);

$response = $client->get('http://inventory-service/check');
```

### Service Name Detection

TraceKit intelligently extracts service names from URLs:

| URL | Extracted Service Name |
|-----|------------------------|
| `http://payment-service:3000` | `payment-service` |
| `http://payment.internal` | `payment` |
| `http://payment.svc.cluster.local` | `payment` |
| `https://api.example.com` | `api.example.com` |

This works seamlessly with:
- Kubernetes service names
- Internal DNS names
- Docker Compose service names
- External APIs

### Custom Service Name Mappings

For local development or when service names can't be inferred from hostnames, use `service_name_mappings`:

```php
$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'my-service',
    // Map localhost URLs to actual service names
    'service_name_mappings' => [
        'localhost:8082' => 'payment-service',
        'localhost:8083' => 'user-service',
        'localhost:8084' => 'inventory-service',
        'localhost:5001' => 'analytics-service',
    ],
]);

// Now requests to localhost:8082 will show as "payment-service" in the service graph
$response = $httpClient->get('http://localhost:8082/charge');
// -> Creates CLIENT span with peer.service = "payment-service"
```

This is especially useful when:
- Running microservices locally on different ports
- Using Docker Compose with localhost networking
- Testing distributed tracing in development

### Complete Example: Multi-Service Application

```php
<?php

require 'vendor/autoload.php';

use TraceKit\PHP\TracekitClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$tracekit = new TracekitClient([
    'api_key' => getenv('TRACEKIT_API_KEY'),
    'service_name' => 'checkout-service',
]);

// Setup Guzzle with TraceKit instrumentation
$stack = HandlerStack::create();
$stack->push($tracekit->getHttpClientInstrumentation()->getGuzzleMiddleware());
$httpClient = new Client(['handler' => $stack]);

// Start request trace
$requestSpan = $tracekit->startTrace('http-request', [
    'http.method' => 'POST',
    'http.url' => '/checkout',
]);

try {
    // These HTTP calls automatically create CLIENT spans
    $paymentResponse = $httpClient->post('http://payment-service/charge', [
        'json' => [
            'amount' => 99.99,
            'user_id' => 123,
        ],
    ]);

    $inventoryResponse = $httpClient->post('http://inventory-service/reserve', [
        'json' => ['item_id' => 456],
    ]);

    $tracekit->endSpan($requestSpan, ['http.status_code' => 200]);

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    $tracekit->recordException($requestSpan, $e);
    $tracekit->endSpan($requestSpan, [], 'ERROR');
    echo json_encode(['error' => $e->getMessage()]);
}

$tracekit->flush();
```

### Viewing Service Dependencies

Visit your TraceKit dashboard to see:

- **Service Map**: Visual graph showing which services call which
- **Service List**: Table of all services with health metrics
- **Service Detail**: Upstream/downstream dependencies with latency and error info

### Why Manual Wrapping?

Unlike Node.js or Python, PHP doesn't support automatic function interception. Therefore:

- **cURL**: Use the wrapper function `wrapCurlExec()`
- **Guzzle**: Add the middleware once when creating the client
- **Other clients**: Create middleware/wrappers as needed

This gives you full control while maintaining zero-overhead when not used.

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
