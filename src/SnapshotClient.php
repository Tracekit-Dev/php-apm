<?php

namespace TraceKit\PHP;

class SnapshotClient
{
    private string $apiKey;
    private string $baseURL;
    private string $serviceName;
    private int $maxVariableDepth;
    private int $maxStringLength;
    private bool $piiScrubbing;
    private array $piiPatterns;
    private array $breakpointsCache = [];
    private array $registrationCache = [];
    private array $locationLabels = []; // Maps breakpoint ID to label

    // Opt-in capture limits (all disabled by default)
    private ?int $captureDepth = null;   // null = use maxVariableDepth for sanitization (backward compat)
    private ?int $maxPayload = null;     // null = unlimited payload bytes
    private ?float $captureTimeout = null; // null = no timeout (seconds)

    // Kill switch: server-initiated monitoring disable
    private bool $killSwitchActive = false;

    // SSE (Server-Sent Events) real-time updates
    // NOTE: SSE is best-effort, only active in long-running CLI processes (queue workers, daemons).
    // In standard PHP-FPM/web request cycles, polling is the primary mechanism.
    // SSE blocks the current process, so it should only be used in persistent processes.
    private ?string $sseEndpoint = null;
    private bool $sseActive = false;

    // Circuit breaker state (in-memory; persists for long-running workers, resets per FPM request)
    private string $cbState = 'closed';
    private ?float $cbOpenedAt = null;
    private array $cbFailureTimestamps = [];
    private int $cbMaxFailures = 3;
    private int $cbWindowSeconds = 60;
    private int $cbCooldownSeconds = 300;
    private array $pendingEvents = [];

    /**
     * Standard 13 PII patterns with typed [REDACTED:type] markers
     */
    private static function defaultPiiPatterns(): array
    {
        return [
            ['pattern' => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', 'marker' => '[REDACTED:email]'],
            ['pattern' => '/\b\d{3}-\d{2}-\d{4}\b/', 'marker' => '[REDACTED:ssn]'],
            ['pattern' => '/\b\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}\b/', 'marker' => '[REDACTED:credit_card]'],
            ['pattern' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', 'marker' => '[REDACTED:phone]'],
            ['pattern' => '/AKIA[0-9A-Z]{16}/', 'marker' => '[REDACTED:aws_key]'],
            ['pattern' => '/aws.{0,20}secret.{0,20}[A-Za-z0-9\/+=]{40}/i', 'marker' => '[REDACTED:aws_secret]'],
            ['pattern' => '/(?:bearer\s+)[A-Za-z0-9._~+\/=\-]{20,}/i', 'marker' => '[REDACTED:oauth_token]'],
            ['pattern' => '/sk_live_[0-9a-zA-Z]{10,}/', 'marker' => '[REDACTED:stripe_key]'],
            ['pattern' => '/(?:password|passwd|pwd)\s*[=:]\s*["\']?[^\s"\']{6,}/i', 'marker' => '[REDACTED:password]'],
            ['pattern' => '/eyJ[A-Za-z0-9_\-]+\.eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', 'marker' => '[REDACTED:jwt]'],
            ['pattern' => '/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/', 'marker' => '[REDACTED:private_key]'],
            ['pattern' => '/(?:api[_\-]?key|apikey)\s*[:=]\s*["\']?[A-Za-z0-9_\-]{20,}/i', 'marker' => '[REDACTED:api_key]'],
        ];
    }

    public function __construct(
        string $apiKey,
        string $baseURL,
        string $serviceName,
        int $maxVariableDepth = 3,
        int $maxStringLength = 1000,
        bool $piiScrubbing = true,
        array $customPiiPatterns = []
    ) {
        $this->apiKey = $apiKey;
        $this->baseURL = $baseURL;
        $this->serviceName = $serviceName;
        $this->maxVariableDepth = $maxVariableDepth;
        $this->maxStringLength = $maxStringLength;
        $this->piiScrubbing = $piiScrubbing;
        $this->piiPatterns = array_merge(self::defaultPiiPatterns(), $customPiiPatterns);
    }

    /** Set opt-in capture depth limit. null = unlimited (default uses maxVariableDepth for sanitization). */
    public function setCaptureDepth(?int $depth): void { $this->captureDepth = $depth; }

    /** Set opt-in max payload size in bytes. null = unlimited (default). */
    public function setMaxPayload(?int $bytes): void { $this->maxPayload = $bytes; }

    /** Set opt-in capture timeout in seconds. null = no timeout (default). */
    public function setCaptureTimeout(?float $seconds): void { $this->captureTimeout = $seconds; }

    /** Configure circuit breaker thresholds (0 = use default). */
    public function configureCircuitBreaker(int $maxFailures = 0, int $windowSeconds = 0, int $cooldownSeconds = 0): void
    {
        if ($maxFailures > 0) $this->cbMaxFailures = $maxFailures;
        if ($windowSeconds > 0) $this->cbWindowSeconds = $windowSeconds;
        if ($cooldownSeconds > 0) $this->cbCooldownSeconds = $cooldownSeconds;
    }

    private function circuitBreakerShouldAllow(): bool
    {
        if ($this->cbState === 'closed') return true;

        // Check cooldown
        if ($this->cbOpenedAt !== null && (microtime(true) - $this->cbOpenedAt) >= $this->cbCooldownSeconds) {
            $this->cbState = 'closed';
            $this->cbFailureTimestamps = [];
            $this->cbOpenedAt = null;
            error_log('TraceKit: Code monitoring resumed');
            return true;
        }

        return false;
    }

    private function circuitBreakerRecordFailure(): bool
    {
        $now = microtime(true);
        $this->cbFailureTimestamps[] = $now;

        // Prune old timestamps
        $cutoff = $now - $this->cbWindowSeconds;
        $this->cbFailureTimestamps = array_values(array_filter(
            $this->cbFailureTimestamps,
            fn($ts) => $ts > $cutoff
        ));

        if (count($this->cbFailureTimestamps) >= $this->cbMaxFailures && $this->cbState === 'closed') {
            $this->cbState = 'open';
            $this->cbOpenedAt = $now;
            error_log("TraceKit: Code monitoring paused ({$this->cbMaxFailures} capture failures in {$this->cbWindowSeconds}s). Auto-resumes in " . ($this->cbCooldownSeconds / 60) . " min.");
            return true;
        }

        return false;
    }

    private function queueCircuitBreakerEvent(): void
    {
        $this->pendingEvents[] = [
            'type' => 'circuit_breaker_tripped',
            'service_name' => $this->serviceName,
            'failure_count' => $this->cbMaxFailures,
            'window_seconds' => $this->cbWindowSeconds,
            'cooldown_seconds' => $this->cbCooldownSeconds,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Manually poll for active breakpoints (call this periodically)
     */
    public function pollOnce(): void
    {
        $this->fetchActiveBreakpoints();
    }

    /**
     * Check and capture snapshot with automatic breakpoint detection.
     * Crash isolation: catches all Throwable so TraceKit never crashes the host application.
     */
    public function checkAndCaptureWithContext(
        ?array $requestContext,
        string $label,
        array $variables = []
    ): void {
        // Kill switch: skip all capture when server has disabled monitoring
        if ($this->killSwitchActive) {
            return;
        }

        try {
            $startTime = microtime(true);

            $location = $this->detectCallerLocation();
            if (!$location) {
                return;
            }

            $locationKey = "{$location['function']}:{$label}";

            // Check if location is registered
            if (!$this->isLocationRegistered($locationKey)) {
                // Auto-register breakpoint
                $breakpoint = $this->autoRegisterBreakpoint($location['file'], $location['line'], $location['function'], $label);
                if ($breakpoint) {
                    $this->registerLocation($locationKey, $breakpoint);
                    // Add to cache immediately so getActiveBreakpoint can find it
                    $this->addBreakpointToCache($breakpoint);
                } else {
                    return;
                }
            }

            // Check if breakpoint is active
            $breakpoint = $this->getActiveBreakpoint($locationKey);
            if (!$breakpoint || !($breakpoint['enabled'] ?? true)) {
                return;
            }

            // Check expiration
            if (isset($breakpoint['expire_at']) && strtotime($breakpoint['expire_at']) < time()) {
                return;
            }

            // Check max captures
            if (isset($breakpoint['max_captures']) && $breakpoint['max_captures'] > 0 &&
                ($breakpoint['capture_count'] ?? 0) >= $breakpoint['max_captures']) {
                return;
            }

            // Check opt-in capture timeout
            if ($this->captureTimeout !== null && (microtime(true) - $startTime) > $this->captureTimeout) {
                error_log('TraceKit: capture timeout exceeded');
                return;
            }

            // Extract request context if not provided
            if ($requestContext === null) {
                $requestContext = $this->extractRequestContext();
            }

            // Scan variables for security issues
            $securityScan = $this->scanForSecurityIssues($variables);

            // Create snapshot
            $snapshot = [
                'breakpoint_id' => $breakpoint['id'] ?? null,
                'service_name' => $this->serviceName,
                'file_path' => $location['file'],
                'function_name' => $location['function'],
                'label' => $label,
                'line_number' => $location['line'],
                'variables' => $securityScan['variables'],
                'security_flags' => $securityScan['flags'],
                'stack_trace' => $this->getStackTrace(),
                'request_context' => $requestContext,
                'captured_at' => date('c'),
            ];

            // Apply opt-in max payload limit
            $payload = json_encode($snapshot);
            if ($this->maxPayload !== null && strlen($payload) > $this->maxPayload) {
                $snapshot['variables'] = [
                    '_truncated' => true,
                    '_payload_size' => strlen($payload),
                    '_max_payload' => $this->maxPayload,
                ];
                $snapshot['security_flags'] = [];
            }

            // Send snapshot
            $this->captureSnapshot($snapshot);
        } catch (\Throwable $t) {
            // Crash isolation: never let TraceKit errors propagate to host application
            error_log('TraceKit: error in capture: ' . $t->getMessage());
        }
    }

    /**
     * Check and capture snapshot at specific location (manual).
     * Crash isolation: catches all Throwable so TraceKit never crashes the host application.
     */
    public function checkAndCapture(
        string $filePath,
        int $lineNumber,
        array $variables = []
    ): void {
        // Kill switch: skip all capture when server has disabled monitoring
        if ($this->killSwitchActive) {
            return;
        }

        try {
            $lineKey = "{$filePath}:{$lineNumber}";
            $breakpoint = $this->getActiveBreakpoint($lineKey);

            if (!$breakpoint || !($breakpoint['enabled'] ?? true)) {
                return;
            }

            $requestContext = $this->extractRequestContext();

            // Scan variables for security issues
            $securityScan = $this->scanForSecurityIssues($variables);

            $snapshot = [
                'breakpoint_id' => $breakpoint['id'] ?? null,
                'service_name' => $this->serviceName,
                'file_path' => $filePath,
                'function_name' => $breakpoint['function_name'] ?? 'unknown',
                'label' => $breakpoint['label'] ?? null,
                'line_number' => $lineNumber,
                'variables' => $securityScan['variables'],
                'security_flags' => $securityScan['flags'],
                'stack_trace' => $this->getStackTrace(),
                'request_context' => $requestContext,
                'captured_at' => date('c'),
            ];

            $this->captureSnapshot($snapshot);
        } catch (\Throwable $t) {
            error_log('TraceKit: error in checkAndCapture: ' . $t->getMessage());
        }
    }

    /**
     * Detect caller location using debug_backtrace
     */
    private function detectCallerLocation(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        // Skip: detectCallerLocation (0), checkAndCaptureWithContext (1), TracekitClient::captureSnapshot (2), actual caller (3)
        $caller = $trace[3] ?? $trace[2] ?? $trace[1] ?? $trace[0];

        if (!$caller) {
            return null;
        }

        return [
            'file' => $caller['file'] ?? '',
            'line' => $caller['line'] ?? 0,
            'function' => $caller['function'] ?? 'anonymous',
        ];
    }

    /**
     * Get formatted stack trace
     */
    private function getStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $formatted = [];

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'anonymous';
            $class = $frame['class'] ?? '';

            if ($class) {
                $formatted[] = "{$class}::{$function}({$file}:{$line})";
            } else {
                $formatted[] = "{$function}({$file}:{$line})";
            }
        }

        return implode("\n", $formatted);
    }

    /**
     * Fetch active breakpoints from backend
     */
    private function fetchActiveBreakpoints(): void
    {
        try {
            $url = "{$this->baseURL}/sdk/snapshots/active/{$this->serviceName}";

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "X-API-Key: {$this->apiKey}\r\n" .
                               "Content-Type: application/json\r\n",
                    'timeout' => 5,
                ]
            ]);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                return;
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('TraceKit: Failed to decode breakpoints response');
                return;
            }

            $this->updateBreakpointCache($data['breakpoints'] ?? []);

            // SSE auto-discovery: if polling response includes sse_endpoint, start SSE in CLI mode
            if (isset($data['sse_endpoint']) && !$this->sseActive && $this->isLongRunning()) {
                $this->sseEndpoint = $data['sse_endpoint'];
                $this->connectSSE($this->sseEndpoint);
            }

            // Handle kill switch state (missing field = false for backward compat)
            $newKillState = ($data['kill_switch'] ?? false) === true;
            if ($newKillState && !$this->killSwitchActive) {
                error_log('TraceKit: Code monitoring disabled by server kill switch. Polling at reduced frequency.');
            } elseif (!$newKillState && $this->killSwitchActive) {
                error_log('TraceKit: Code monitoring re-enabled by server.');
            }
            $this->killSwitchActive = $newKillState;

        } catch (\Exception $e) {
            error_log('TraceKit: Failed to fetch breakpoints: ' . $e->getMessage());
        }
    }

    /**
     * Add single breakpoint to cache
     */
    private function addBreakpointToCache(array $breakpoint): void
    {
        // Add label to breakpoint if not present (for local cache consistency)
        if (!isset($breakpoint['label']) && isset($this->locationLabels[$breakpoint['id'] ?? ''])) {
            $breakpoint['label'] = $this->locationLabels[$breakpoint['id'] ?? ''];
        }

        // Check if breakpoint already exists in cache
        foreach ($this->breakpointsCache as &$existing) {
            if (($existing['id'] ?? null) === ($breakpoint['id'] ?? null)) {
                $existing = $breakpoint; // Update existing
                return;
            }
        }

        // Add new breakpoint to cache
        $this->breakpointsCache[] = $breakpoint;
    }

    /**
     * Update breakpoint cache
     */
    private function updateBreakpointCache(array $breakpoints): void
    {
        // Preserve manually added labels when updating from server
        foreach ($breakpoints as &$breakpoint) {
            $id = $breakpoint['id'] ?? null;
            if ($id && isset($this->locationLabels[$id])) {
                $breakpoint['label'] = $this->locationLabels[$id];
            }
        }

        $this->breakpointsCache = $breakpoints;

        if (!empty($breakpoints)) {
            error_log("TraceKit: Updated breakpoint cache: " . count($breakpoints) . " active breakpoints");
        }
    }

    /**
     * Get active breakpoint for location
     */
    private function getActiveBreakpoint(string $locationKey): ?array
    {
        // Primary key: function + label
        if (strpos($locationKey, ':') !== false) {
            list($function, $label) = explode(':', $locationKey, 2);
            foreach ($this->breakpointsCache as $bp) {
                $bpFunction = $bp['function_name'] ?? '';
                $bpLabel = $bp['label'] ?? '';
                if ($bpFunction === $function && $bpLabel === $label) {
                    return $bp;
                }
            }
        }

        // Secondary key: file + line
        foreach ($this->breakpointsCache as $bp) {
            $lineKey = ($bp['file_path'] ?? '') . ':' . ($bp['line_number'] ?? 0);
            if ($lineKey === $locationKey) {
                return $bp;
            }
        }

        return null;
    }

    /**
     * Check if location is registered
     */
    private function isLocationRegistered(string $locationKey): bool
    {
        return in_array($locationKey, $this->registrationCache);
    }

    /**
     * Register location
     */
    private function registerLocation(string $locationKey, array $breakpoint): void
    {
        $this->registrationCache[] = $locationKey;
        $this->registrationCache = array_unique($this->registrationCache);

        // Store label mapping for this breakpoint
        if (isset($breakpoint['id'])) {
            $this->locationLabels[$breakpoint['id']] = $this->extractLabelFromKey($locationKey);
        }
    }

    /**
     * Extract label from location key (format: function:label)
     */
    private function extractLabelFromKey(string $locationKey): string
    {
        $parts = explode(':', $locationKey, 2);
        return $parts[1] ?? '';
    }

    /**
     * Extract function from location key (format: function:label)
     */
    private function extractFunctionFromKey(string $locationKey): string
    {
        $parts = explode(':', $locationKey, 2);
        return $parts[0] ?? '';
    }

    /**
     * Auto-register breakpoint
     */
    private function autoRegisterBreakpoint(string $filePath, int $lineNumber, string $functionName, string $label): ?array
    {
        try {
            $url = "{$this->baseURL}/sdk/snapshots/auto-register";

            $payload = json_encode([
                'service_name' => $this->serviceName,
                'file_path' => $filePath,
                'line_number' => $lineNumber,
                'function_name' => $functionName,
                'label' => $label,
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "X-API-Key: {$this->apiKey}\r\n" .
                               "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 5,
                ]
            ]);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                error_log('TraceKit: Failed to auto-register breakpoint - network error');
                return null;
            }

            $breakpoint = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('TraceKit: Failed to decode auto-register response');
                return null;
            }

            // Server response may only contain {id}, so augment with known data
            $breakpoint['file_path'] = $breakpoint['file_path'] ?? $filePath;
            $breakpoint['line_number'] = $breakpoint['line_number'] ?? $lineNumber;
            $breakpoint['function_name'] = $breakpoint['function_name'] ?? $functionName;
            $breakpoint['label'] = $breakpoint['label'] ?? $label;
            $breakpoint['enabled'] = $breakpoint['enabled'] ?? true;

            error_log("TraceKit: Auto-registered breakpoint: {$label}");

            return $breakpoint;

        } catch (\Exception $e) {
            error_log('TraceKit: Failed to auto-register breakpoint: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Capture and send snapshot
     */
    private function captureSnapshot(array $snapshot): void
    {
        // Circuit breaker check
        if (!$this->circuitBreakerShouldAllow()) {
            return;
        }

        try {
            $url = "{$this->baseURL}/sdk/snapshots/capture";

            $payload = json_encode($snapshot);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "X-API-Key: {$this->apiKey}\r\n" .
                               "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ]
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                // Network error -- count as circuit breaker failure
                error_log('TraceKit: Failed to capture snapshot - network error');
                if ($this->circuitBreakerRecordFailure()) {
                    $this->queueCircuitBreakerEvent();
                }
                return;
            }

            // Check for HTTP 5xx via response headers
            $statusCode = $this->extractHttpStatusCode($http_response_header ?? []);
            if ($statusCode >= 500) {
                // Server error -- count as circuit breaker failure
                if ($this->circuitBreakerRecordFailure()) {
                    $this->queueCircuitBreakerEvent();
                }
            }

        } catch (\Exception $e) {
            error_log('TraceKit: Failed to capture snapshot: ' . $e->getMessage());
            if ($this->circuitBreakerRecordFailure()) {
                $this->queueCircuitBreakerEvent();
            }
        }
    }

    /**
     * Extract HTTP status code from response headers
     */
    private function extractHttpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }

    /**
     * Check if running in a long-running process (CLI worker, daemon).
     * SSE is only activated in CLI mode since it blocks the process.
     * In web request mode (FPM/CGI), polling is the primary mechanism.
     */
    private function isLongRunning(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Connect to the SSE endpoint for real-time breakpoint updates.
     * This method blocks while reading the SSE stream, so it should only be called
     * in long-running CLI processes (queue workers, daemons).
     * Falls back to polling if SSE connection fails or disconnects.
     * Crash isolation: wraps all operations in try/catch(\Throwable).
     */
    private function connectSSE(string $endpoint): void
    {
        try {
            $fullUrl = rtrim($this->baseURL, '/') . $endpoint;

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "X-API-Key: {$this->apiKey}\r\n" .
                               "Accept: text/event-stream\r\n" .
                               "Cache-Control: no-cache\r\n",
                    'timeout' => 0, // No timeout for SSE (long-lived connection)
                ]
            ]);

            $stream = @fopen($fullUrl, 'r', false, $context);

            if ($stream === false) {
                error_log('TraceKit: SSE connection failed, falling back to polling');
                $this->sseActive = false;
                return;
            }

            // Disable blocking timeout for the stream
            stream_set_timeout($stream, 0);

            $this->sseActive = true;
            error_log("TraceKit: SSE connected to {$endpoint}");

            $eventType = null;
            $eventData = '';

            while (!feof($stream)) {
                $line = fgets($stream);

                if ($line === false) {
                    break;
                }

                $line = trim($line);

                if (strpos($line, 'event:') === 0) {
                    $eventType = trim(substr($line, 6));
                } elseif (strpos($line, 'data:') === 0) {
                    $eventData .= trim(substr($line, 5));
                } elseif ($line === '' && $eventType !== null) {
                    // Empty line signals end of event -- process it
                    $this->handleSSEEvent($eventType, $eventData);
                    $eventType = null;
                    $eventData = '';
                }
            }

            fclose($stream);

            // Connection closed
            $this->sseActive = false;
            error_log('TraceKit: SSE connection closed, falling back to polling');

        } catch (\Throwable $t) {
            // Crash isolation: never let SSE errors propagate
            error_log('TraceKit: SSE error: ' . $t->getMessage());
            $this->sseActive = false;
        }
    }

    /**
     * Handle a parsed SSE event
     */
    private function handleSSEEvent(string $eventType, string $dataStr): void
    {
        try {
            $payload = json_decode($dataStr, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("TraceKit: SSE JSON parse error for '{$eventType}'");
                return;
            }

            switch ($eventType) {
                case 'init':
                    // Replace entire breakpoint cache from init event
                    $this->updateBreakpointCache($payload['breakpoints'] ?? []);

                    // Update kill switch from init event
                    if (isset($payload['kill_switch']) && $payload['kill_switch'] === true) {
                        $this->killSwitchActive = true;
                        error_log('TraceKit: Code monitoring disabled by server kill switch via SSE.');
                        $this->sseActive = false;
                    }
                    break;

                case 'breakpoint_created':
                case 'breakpoint_updated':
                    // Upsert breakpoint in cache
                    $this->upsertBreakpointInCache($payload);
                    break;

                case 'breakpoint_deleted':
                    // Remove breakpoint from cache by ID
                    $this->removeBreakpointFromCache($payload['id'] ?? null);
                    break;

                case 'kill_switch':
                    $this->killSwitchActive = ($payload['enabled'] ?? false) === true;
                    if ($this->killSwitchActive) {
                        error_log('TraceKit: Code monitoring disabled by server kill switch via SSE.');
                        $this->sseActive = false;
                    }
                    break;

                case 'heartbeat':
                    // No action needed -- keeps connection alive
                    break;

                default:
                    // Unknown event type, ignore
                    break;
            }
        } catch (\Throwable $t) {
            error_log("TraceKit: SSE event handling error: " . $t->getMessage());
        }
    }

    /**
     * Upsert a single breakpoint into the cache
     */
    private function upsertBreakpointInCache(array $breakpoint): void
    {
        $bpId = $breakpoint['id'] ?? null;
        if ($bpId === null) {
            return;
        }

        // Update existing or add new
        foreach ($this->breakpointsCache as $i => $existing) {
            if (($existing['id'] ?? null) === $bpId) {
                $this->breakpointsCache[$i] = $breakpoint;
                return;
            }
        }

        $this->breakpointsCache[] = $breakpoint;
    }

    /**
     * Remove a breakpoint from the cache by ID
     */
    private function removeBreakpointFromCache(?string $breakpointId): void
    {
        if ($breakpointId === null) {
            return;
        }

        $this->breakpointsCache = array_values(array_filter(
            $this->breakpointsCache,
            fn($bp) => ($bp['id'] ?? null) !== $breakpointId
        ));
    }

    /**
     * Extract request context from globals
     */
    private function extractRequestContext(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'query' => $_GET ?? [],
            'headers' => $this->getHeaders(),
        ];
    }

    /**
     * Get client IP address
     */
    private function getClientIP(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                return $_SERVER[$header];
            }
        }

        return 'unknown';
    }

    /**
     * Get request headers
     */
    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        // Fallback for non-Apache servers
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /**
     * Filter sensitive headers
     */
    private function filterHeaders(array $headers): array
    {
        $allowed = ['content-type', 'content-length', 'host', 'user-agent', 'referer'];
        $filtered = [];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $allowed)) {
                $filtered[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $filtered;
    }

    /**
     * Scan variables for security issues using typed [REDACTED:type] markers.
     * Scans serialized JSON to catch nested PII. Skips when piiScrubbing is false.
     */
    private function scanForSecurityIssues(array $variables): array
    {
        // If PII scrubbing is disabled, return as-is
        if (!$this->piiScrubbing) {
            return [
                'variables' => $variables,
                'flags' => [],
            ];
        }

        // Letter-boundary pattern for sensitive variable names.
        // \b treats _ as word char, so api_key/user_token won't match. Use letter boundaries instead.
        $sensitiveNamePattern = '/(?:^|[^a-zA-Z])(?:password|passwd|pwd|secret|token|key|credential|api_key|apikey)(?:[^a-zA-Z]|$)/i';

        $securityFlags = [];
        $sanitized = $this->sanitizeVariables($variables);

        foreach ($variables as $name => $value) {
            // Check variable name for sensitive keywords
            if (preg_match($sensitiveNamePattern, $name)) {
                $securityFlags[] = [
                    'type' => 'sensitive_variable_name',
                    'severity' => 'medium',
                    'variable' => $name,
                ];
                $sanitized[$name] = '[REDACTED:sensitive_name]';
                continue;
            }

            // Serialize value to JSON for deep scanning of nested structures
            $serialized = json_encode($value);
            $flagged = false;
            foreach ($this->piiPatterns as $pp) {
                if (preg_match($pp['pattern'], $serialized)) {
                    $securityFlags[] = [
                        'type' => "sensitive_data",
                        'severity' => 'high',
                        'variable' => $name,
                    ];
                    $sanitized[$name] = $pp['marker'];
                    $flagged = true;
                    break;
                }
            }
        }

        return [
            'variables' => $sanitized,
            'flags' => $securityFlags,
        ];
    }

    /**
     * Sanitize variables for JSON serialization
     */
    private function sanitizeVariables(array $variables, int $depth = 0): array
    {
        if ($depth >= $this->maxVariableDepth) {
            return []; // Stop recursion at max depth, don't replace with error
        }

        $sanitized = [];

        foreach ($variables as $key => $value) {
            try {
                if (is_string($value) && strlen($value) > $this->maxStringLength) {
                    $sanitized[$key] = substr($value, 0, $this->maxStringLength) . '...';
                } elseif (is_object($value)) {
                    $sanitized[$key] = $this->sanitizeObject($value, $depth + 1);
                } elseif (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeVariables($value, $depth + 1);
                } elseif (is_resource($value)) {
                    $sanitized[$key] = '[resource]';
                } else {
                    // Test if serializable
                    json_encode($value);
                    $sanitized[$key] = $value;
                }
            } catch (\Exception $e) {
                $sanitized[$key] = '[' . gettype($value) . ']';
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize objects
     */
    private function sanitizeObject($object, int $depth): array
    {
        if ($depth >= $this->maxVariableDepth) {
            return ['class' => get_class($object)]; // Stop recursion, just return class name
        }

        try {
            return [
                'class' => get_class($object),
                'properties' => $this->sanitizeVariables(get_object_vars($object), $depth + 1),
            ];
        } catch (\Exception $e) {
            return ['class' => get_class($object), 'error' => 'Could not serialize'];
        }
    }
}
