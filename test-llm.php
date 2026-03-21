<?php

require_once __DIR__ . '/vendor/autoload.php';

use TraceKit\PHP\TracekitClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

// Read API keys from environment
$openaiKey = getenv('OPENAI_API_KEY');
$anthropicKey = getenv('ANTHROPIC_API_KEY');
$tracekitKey = getenv('TRACEKIT_API_KEY');

if (!$tracekitKey) {
    echo "[ERROR] TRACEKIT_API_KEY is not set.\n";
    echo "Set your TraceKit API key (ctxio_...) to authenticate trace exports.\n";
    exit(1);
}

if (!$openaiKey && !$anthropicKey) {
    echo "[ERROR] Neither OPENAI_API_KEY nor ANTHROPIC_API_KEY is set.\n";
    echo "Set at least one API key to run LLM tests.\n";
    exit(1);
}

// Initialize TracekitClient with LLM instrumentation enabled
$tracekit = new TracekitClient([
    'api_key' => $tracekitKey,
    'service_name' => 'php-llm-test',
    'endpoint' => 'http://localhost:8081/v1/traces',
    'suppress_errors' => false,
    'llm' => [
        'capture_content' => true,
        'openai' => true,
        'anthropic' => true,
    ],
]);

// Build instrumented Guzzle client
$stack = HandlerStack::create();
$llmMiddleware = $tracekit->getLlmMiddleware();
if ($llmMiddleware) {
    $stack->push($llmMiddleware, 'tracekit-llm');
}
$httpClient = new Client(['handler' => $stack]);

// ---------- Test Functions ----------

function testOpenAiNonStreaming(Client $httpClient, string $apiKey): bool
{
    echo "[TEST] OpenAI non-streaming...\n";
    try {
        $response = $httpClient->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => 'Say hello in exactly 3 words.'],
                ],
                'max_tokens' => 50,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $content = $data['choices'][0]['message']['content'] ?? '(no content)';
        $model = $data['model'] ?? '(unknown)';
        $finishReason = $data['choices'][0]['finish_reason'] ?? '(unknown)';

        echo "  Model: $model\n";
        echo "  Content: $content\n";
        echo "  Finish reason: $finishReason\n";
        echo "[PASS] OpenAI non-streaming\n\n";
        return true;
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        echo "[FAIL] OpenAI non-streaming\n\n";
        return false;
    }
}

function testOpenAiStreaming(Client $httpClient, string $apiKey): bool
{
    echo "[TEST] OpenAI streaming...\n";
    try {
        $response = $httpClient->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => 'Count from 1 to 5.'],
                ],
                'max_tokens' => 100,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ],
            'stream' => true,
        ]);

        $body = $response->getBody();
        $accumulated = '';
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (strpos($line, 'data: ') === 0) {
                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        break 2;
                    }
                    $event = json_decode($json, true);
                    if ($event && isset($event['choices'][0]['delta']['content'])) {
                        $accumulated .= $event['choices'][0]['delta']['content'];
                    }
                }
            }
        }

        echo "  Content: $accumulated\n";
        echo "[PASS] OpenAI streaming\n\n";
        return true;
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        echo "[FAIL] OpenAI streaming\n\n";
        return false;
    }
}

function testAnthropicNonStreaming(Client $httpClient, string $apiKey): bool
{
    echo "[TEST] Anthropic non-streaming...\n";
    try {
        $response = $httpClient->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'claude-3-5-haiku-20241022',
                'max_tokens' => 50,
                'messages' => [
                    ['role' => 'user', 'content' => 'Say hello in exactly 3 words.'],
                ],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $content = $data['content'][0]['text'] ?? '(no content)';
        $model = $data['model'] ?? '(unknown)';
        $stopReason = $data['stop_reason'] ?? '(unknown)';

        echo "  Model: $model\n";
        echo "  Content: $content\n";
        echo "  Stop reason: $stopReason\n";
        echo "[PASS] Anthropic non-streaming\n\n";
        return true;
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        echo "[FAIL] Anthropic non-streaming\n\n";
        return false;
    }
}

function testAnthropicStreaming(Client $httpClient, string $apiKey): bool
{
    echo "[TEST] Anthropic streaming...\n";
    try {
        $response = $httpClient->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'claude-3-5-haiku-20241022',
                'max_tokens' => 100,
                'messages' => [
                    ['role' => 'user', 'content' => 'Count from 1 to 5.'],
                ],
                'stream' => true,
            ],
            'stream' => true,
        ]);

        $body = $response->getBody();
        $accumulated = '';
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (strpos($line, 'data: ') === 0) {
                    $json = substr($line, 6);
                    $event = json_decode($json, true);
                    if ($event && ($event['type'] ?? '') === 'content_block_delta') {
                        $accumulated .= $event['delta']['text'] ?? '';
                    }
                }
            }
        }

        echo "  Content: $accumulated\n";
        echo "[PASS] Anthropic streaming\n\n";
        return true;
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        echo "[FAIL] Anthropic streaming\n\n";
        return false;
    }
}

// ---------- Main Execution ----------

if (php_sapi_name() === 'cli') {
    echo "TraceKit PHP LLM Instrumentation Test\n";
    echo "=====================================\n\n";

    $passed = 0;
    $skipped = 0;
    $failed = 0;

    // OpenAI tests
    if ($openaiKey) {
        if (testOpenAiNonStreaming($httpClient, $openaiKey)) {
            $passed++;
        } else {
            $failed++;
        }
        if (testOpenAiStreaming($httpClient, $openaiKey)) {
            $passed++;
        } else {
            $failed++;
        }
    } else {
        echo "[SKIP] OpenAI tests -- OPENAI_API_KEY not set\n\n";
        $skipped += 2;
    }

    // Anthropic tests
    if ($anthropicKey) {
        if (testAnthropicNonStreaming($httpClient, $anthropicKey)) {
            $passed++;
        } else {
            $failed++;
        }
        if (testAnthropicStreaming($httpClient, $anthropicKey)) {
            $passed++;
        } else {
            $failed++;
        }
    } else {
        echo "[SKIP] Anthropic tests -- ANTHROPIC_API_KEY not set\n\n";
        $skipped += 2;
    }

    // Summary
    echo "--- Results ---\n";
    echo "Passed: $passed, Failed: $failed, Skipped: $skipped\n";

    // Flush traces
    $tracekit->shutdown();
    echo "\nTraces sent to http://localhost:8081 -- check dashboard for gen_ai.* spans\n";

    exit($failed > 0 ? 1 : 0);
}
