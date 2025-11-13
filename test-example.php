<?php

require_once __DIR__ . '/vendor/autoload.php';

use TraceKit\PHP\TracekitClient;

// Initialize TraceKit with code monitoring
$client = new TracekitClient([
    'api_key' => 'your-api-key-here',
    'service_name' => 'php-test-app',
    'endpoint' => 'http://localhost:8081/v1/traces',
    'code_monitoring_enabled' => true,
    'code_monitoring_max_depth' => 3,
    'code_monitoring_max_string' => 1000,
]);

// Poll for breakpoints every 30 seconds (in a real app, use a cron job)
// In a web app, you might call this on every Nth request
$client->pollBreakpoints();

// Simulate a web request handler
function handleCheckout($userId, $cart)
{
    global $client;

    // Automatic snapshot capture with label
    $client->captureSnapshot('checkout-validation', [
        'user_id' => $userId,
        'cart_items' => count($cart['items'] ?? []),
        'total_amount' => $cart['total'] ?? 0,
        'currency' => $cart['currency'] ?? 'USD',
    ]);

    // Process payment
    $result = processPayment($cart['total'], $userId);

    // Another checkpoint
    $client->captureSnapshot('payment-success', [
        'user_id' => $userId,
        'payment_id' => $result['payment_id'],
        'amount' => $result['amount'],
        'status' => 'completed',
    ]);

    return $result;
}

// Simulate an API endpoint
function handleApiRequest()
{
    global $client;

    try {
        // Simulate request data
        $userId = $_POST['user_id'] ?? 123;
        $cart = $_POST['cart'] ?? ['total' => 99.99, 'items' => ['item1', 'item2']];

        $result = handleCheckout($userId, $cart);

        echo json_encode(['success' => true, 'data' => $result]);

    } catch (Exception $e) {
        // Automatic error capture
        $client->captureSnapshot('checkout-error', [
            'user_id' => $userId ?? null,
            'error_type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        http_response_code(500);
        echo json_encode(['error' => 'Payment failed']);
    }
}

// Simulate order history endpoint
function handleOrderHistory($userId)
{
    global $client;

    // Debug checkpoint
    $client->captureSnapshot('order-history-query', [
        'user_id' => $userId,
        'limit' => 20,
        'page' => 1,
    ]);

    // Simulate database query
    $orders = [
        ['id' => 1, 'total' => 49.99, 'status' => 'completed'],
        ['id' => 2, 'total' => 29.99, 'status' => 'pending'],
    ];

    // Capture result
    $client->captureSnapshot('order-history-loaded', [
        'user_id' => $userId,
        'total_orders' => count($orders),
        'page' => 1,
    ]);

    return $orders;
}

// Simulate payment processing
function processPayment($amount, $userId)
{
    if ($amount > 1000) {
        throw new Exception('Amount exceeds limit');
    }

    // Simulate processing delay
    usleep(100000); // 100ms

    return [
        'payment_id' => 'pay_' . uniqid(),
        'amount' => $amount,
        'status' => 'succeeded',
    ];
}

// CLI usage example
if (php_sapi_name() === 'cli') {
    echo "TraceKit PHP Code Monitoring Test\n";
    echo "=================================\n\n";

    echo "Code monitoring enabled: " . ($client->isCodeMonitoringEnabled() ? 'YES' : 'NO') . "\n\n";

    // Simulate checkout
    try {
        echo "Simulating checkout...\n";
        $result = handleCheckout(123, ['total' => 99.99, 'items' => ['item1', 'item2']]);
        echo "Checkout successful: " . json_encode($result) . "\n\n";
    } catch (Exception $e) {
        echo "Checkout failed: " . $e->getMessage() . "\n\n";
    }

    // Simulate order history
    echo "Simulating order history...\n";
    $orders = handleOrderHistory(123);
    echo "Found " . count($orders) . " orders\n\n";

    echo "Check your TraceKit dashboard for captured snapshots!\n";
    echo "Make sure breakpoints are active for the labels used above.\n";

} else {
    // Web server usage
    $route = $_SERVER['REQUEST_URI'] ?? '/';

    switch ($route) {
        case '/checkout':
            handleApiRequest();
            break;
        case '/orders':
            $userId = $_GET['user_id'] ?? null;
            if ($userId) {
                $orders = handleOrderHistory($userId);
                echo json_encode($orders);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing user_id']);
            }
            break;
        default:
            echo json_encode(['message' => 'PHP Test App', 'code_monitoring' => $client->isCodeMonitoringEnabled()]);
    }
}

// Cleanup
$client->shutdown();
