<?php

namespace TraceKit\PHP\Integrations;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Shared LLM instrumentation helpers used by all provider handlers.
 *
 * Provides: provider detection, attribute setters, PII scrubbing,
 * content capture with 4KB truncation, and config resolution.
 *
 * LLM Config array shape:
 * ```php
 * $llmConfig = [
 *     'enabled' => true,          // master toggle
 *     'openai' => true,           // per-provider
 *     'anthropic' => true,        // per-provider
 *     'capture_content' => false, // content capture toggle
 * ];
 * ```
 */
class LlmCommon
{
    /** Maximum bytes for content capture attributes (PHP memory safety). */
    private const MAX_CONTENT_BYTES = 4096;

    /** Case-insensitive pattern for sensitive JSON keys. */
    private const SENSITIVE_KEY_PATTERN = '/^(password|passwd|pwd|secret|token|key|credential|api_key|apikey)$/i';

    /**
     * PII regex patterns applied to content strings.
     * Each replaces the match with [REDACTED].
     */
    private const PII_PATTERNS = [
        // 1. Email addresses
        '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
        // 2. US Social Security Numbers
        '/\b\d{3}-\d{2}-\d{4}\b/',
        // 3. Credit card numbers (16 digits with optional separators)
        '/\b\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}\b/',
        // 4. AWS access keys
        '/AKIA[0-9A-Z]{16}/',
        // 5. Bearer/OAuth tokens
        '/(?i)(?:bearer\s+)[A-Za-z0-9._~+\/=\-]{20,}/',
        // 6. Stripe secret keys
        '/sk_live_[0-9a-zA-Z]{10,}/',
        // 7. JWTs (three base64url segments)
        '/eyJ[A-Za-z0-9_\-]+\.eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/',
        // 8. Private key headers
        '/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/',
    ];

    /**
     * Detect the LLM provider from a request host.
     *
     * @param string $host Hostname, optionally with port (e.g. "api.openai.com:443")
     * @return string|null "openai", "anthropic", or null if unknown
     */
    public static function detectProvider(string $host): ?string
    {
        // Strip port if present
        $hostname = strtolower(parse_url("https://{$host}", PHP_URL_HOST) ?? $host);

        return match ($hostname) {
            'api.openai.com' => 'openai',
            'api.anthropic.com' => 'anthropic',
            default => null,
        };
    }

    /**
     * Determine if content capture is enabled.
     *
     * Env var TRACEKIT_LLM_CAPTURE_CONTENT takes precedence over config.
     *
     * @param array $config LLM config array
     * @return bool
     */
    public static function shouldCaptureContent(array $config): bool
    {
        $envVal = getenv('TRACEKIT_LLM_CAPTURE_CONTENT');

        if ($envVal !== false && $envVal !== '') {
            $lower = strtolower($envVal);
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0') {
                return false;
            }
        }

        return $config['capture_content'] ?? false;
    }

    /**
     * Set GenAI request attributes on a span.
     *
     * @param SpanInterface $span    The active span
     * @param string        $provider Provider name ("openai" or "anthropic")
     * @param string        $model   Model name from the request
     * @param int|null      $maxTokens  Max tokens (set only if non-null)
     * @param float|null    $temperature Temperature (set only if non-null)
     * @param float|null    $topP    Top-p (set only if non-null)
     */
    public static function setGenAIRequestAttributes(
        SpanInterface $span,
        string $provider,
        string $model,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?float $topP = null
    ): void {
        $span->setAttribute('gen_ai.operation.name', 'chat');
        $span->setAttribute('gen_ai.system', $provider);
        $span->setAttribute('gen_ai.request.model', $model);

        if ($maxTokens !== null) {
            $span->setAttribute('gen_ai.request.max_tokens', $maxTokens);
        }
        if ($temperature !== null) {
            $span->setAttribute('gen_ai.request.temperature', $temperature);
        }
        if ($topP !== null) {
            $span->setAttribute('gen_ai.request.top_p', $topP);
        }
    }

    /**
     * Set GenAI response attributes on a span.
     *
     * Only sets attributes that are non-null / non-empty.
     *
     * @param SpanInterface $span
     * @param string|null   $responseModel  Actual model used
     * @param string|null   $responseId     Response ID
     * @param string[]|null $finishReasons  Array of finish reason strings
     * @param int|null      $inputTokens    Prompt token count
     * @param int|null      $outputTokens   Completion token count
     */
    public static function setGenAIResponseAttributes(
        SpanInterface $span,
        ?string $responseModel = null,
        ?string $responseId = null,
        ?array $finishReasons = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null
    ): void {
        if ($responseModel !== null && $responseModel !== '') {
            $span->setAttribute('gen_ai.response.model', $responseModel);
        }
        if ($responseId !== null && $responseId !== '') {
            $span->setAttribute('gen_ai.response.id', $responseId);
        }
        if ($finishReasons !== null && count($finishReasons) > 0) {
            $span->setAttribute('gen_ai.response.finish_reasons', $finishReasons);
        }
        if ($inputTokens !== null) {
            $span->setAttribute('gen_ai.usage.input_tokens', $inputTokens);
        }
        if ($outputTokens !== null) {
            $span->setAttribute('gen_ai.usage.output_tokens', $outputTokens);
        }
    }

    /**
     * Set error attributes on a span for a GenAI error.
     *
     * Sets error.type, span status to ERROR, and records the exception.
     *
     * @param SpanInterface $span
     * @param \Throwable    $error
     */
    public static function setGenAIErrorAttributes(SpanInterface $span, \Throwable $error): void
    {
        $span->setAttribute('error.type', get_class($error));
        $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        $span->recordException($error);
    }

    /**
     * Record a tool call as a span event.
     *
     * @param SpanInterface $span
     * @param string        $name      Tool/function name
     * @param string|null   $callId    Tool call ID
     * @param string|null   $arguments JSON-serialized arguments
     */
    public static function recordToolCallEvent(
        SpanInterface $span,
        string $name,
        ?string $callId = null,
        ?string $arguments = null
    ): void {
        $attrs = ['gen_ai.tool.name' => $name];

        if ($callId !== null && $callId !== '') {
            $attrs['gen_ai.tool.call.id'] = $callId;
        }
        if ($arguments !== null && $arguments !== '') {
            $attrs['gen_ai.tool.call.arguments'] = $arguments;
        }

        $span->addEvent('gen_ai.tool.call', $attrs);
    }

    /**
     * Scrub PII from a content string.
     *
     * If the content is valid JSON, performs key-based scrubbing (replacing
     * values of sensitive keys with [REDACTED]) then re-encodes and applies
     * pattern-based scrubbing. For plain strings, only pattern scrubbing is applied.
     *
     * @param string $content Raw content
     * @return string Scrubbed content
     */
    public static function scrubPII(string $content): string
    {
        // Try JSON-based key scrubbing first
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
            $scrubbed = self::scrubKeys($decoded);
            $content = json_encode($scrubbed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Apply pattern-based scrubbing
        return self::scrubPatterns($content);
    }

    /**
     * Capture input messages on the span (JSON-encoded, PII-scrubbed, truncated).
     *
     * @param SpanInterface $span
     * @param mixed         $messages Messages array or value to capture
     */
    public static function captureInputMessages(SpanInterface $span, $messages): void
    {
        if ($messages === null) {
            return;
        }

        $json = json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $scrubbed = self::scrubPII($json);
        $span->setAttribute('gen_ai.input.messages', self::truncate($scrubbed));
    }

    /**
     * Capture output messages on the span (JSON-encoded, PII-scrubbed, truncated).
     *
     * @param SpanInterface $span
     * @param mixed         $content Output content to capture
     */
    public static function captureOutputMessages(SpanInterface $span, $content): void
    {
        if ($content === null) {
            return;
        }

        $json = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $scrubbed = self::scrubPII($json);
        $span->setAttribute('gen_ai.output.messages', self::truncate($scrubbed));
    }

    /**
     * Capture system instructions on the span (JSON-encoded, PII-scrubbed, truncated).
     *
     * @param SpanInterface $span
     * @param mixed         $system System prompt value
     */
    public static function captureSystemInstructions(SpanInterface $span, $system): void
    {
        if ($system === null) {
            return;
        }

        if (is_string($system)) {
            $serialized = $system;
        } else {
            $serialized = json_encode($system, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($serialized === false) {
                return;
            }
        }

        $scrubbed = self::scrubPII($serialized);
        $span->setAttribute('gen_ai.system_instructions', self::truncate($scrubbed));
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Recursively scrub values of sensitive JSON keys.
     *
     * @param mixed $data Decoded JSON data
     * @return mixed Scrubbed data
     */
    private static function scrubKeys($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = self::scrubKeys($value);
                }
            }
            return $result;
        }

        return $data;
    }

    /**
     * Apply pattern-based PII scrubbing to a string.
     *
     * @param string $content
     * @return string
     */
    private static function scrubPatterns(string $content): string
    {
        foreach (self::PII_PATTERNS as $pattern) {
            $content = preg_replace($pattern, '[REDACTED]', $content);
        }

        return $content;
    }

    /**
     * Truncate a string to MAX_CONTENT_BYTES.
     *
     * @param string $value
     * @return string
     */
    private static function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_CONTENT_BYTES) {
            return $value;
        }

        return substr($value, 0, self::MAX_CONTENT_BYTES);
    }
}
