<?php

namespace TraceKit\PHP;

class SnapshotClient
{
    private string $apiKey;
    private string $baseURL;
    private string $serviceName;
    private int $maxVariableDepth;
    private int $maxStringLength;
    private array $breakpointsCache = [];
    private array $registrationCache = [];
    private array $locationLabels = []; // Maps breakpoint ID to label

    public function __construct(
        string $apiKey,
        string $baseURL,
        string $serviceName,
        int $maxVariableDepth = 3,
        int $maxStringLength = 1000
    ) {
        $this->apiKey = $apiKey;
        $this->baseURL = $baseURL;
        $this->serviceName = $serviceName;
        $this->maxVariableDepth = $maxVariableDepth;
        $this->maxStringLength = $maxStringLength;
    }

    /**
     * Manually poll for active breakpoints (call this periodically)
     */
    public function pollOnce(): void
    {
        $this->fetchActiveBreakpoints();
    }

    /**
     * Check and capture snapshot with automatic breakpoint detection
     */
    public function checkAndCaptureWithContext(
        ?array $requestContext,
        string $label,
        array $variables = []
    ): void {
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

        // Send snapshot
        $this->captureSnapshot($snapshot);
    }

    /**
     * Check and capture snapshot at specific location (manual)
     */
    public function checkAndCapture(
        string $filePath,
        int $lineNumber,
        array $variables = []
    ): void {
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
                ]
            ]);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                error_log('TraceKit: Failed to capture snapshot - network error');
                return;
            }

            // Snapshot captured successfully

        } catch (\Exception $e) {
            error_log('TraceKit: Failed to capture snapshot: ' . $e->getMessage());
        }
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
     * Scan variables for security issues (passwords, API keys, etc.)
     */
    private function scanForSecurityIssues(array $variables): array
    {
        $sensitivePatterns = [
            'password' => '/(?i)(password|passwd|pwd)\s*[=:]\s*["\']?[^\s"\']{6,}/',
            'api_key'  => '/(?i)(api[_-]?key|apikey)\s*[=:]\s*["\']?[A-Za-z0-9_-]{20,}/',
            'jwt'      => '/eyJ[A-Za-z0-9_-]*\.eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/',
            'credit_card' => '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14})\b/',
        ];

        $securityFlags = [];
        $sanitized = $this->sanitizeVariables($variables);

        // Scan variable names
        foreach ($variables as $name => $value) {
            if (preg_match('/password|secret|token|key|credential/i', $name)) {
                $securityFlags[] = [
                    'type' => 'sensitive_variable_name',
                    'severity' => 'medium',
                    'variable' => $name,
                ];
                $sanitized[$name] = '[REDACTED]';
                continue;
            }

            // Scan variable values
            $serialized = json_encode($value);
            foreach ($sensitivePatterns as $type => $pattern) {
                if (preg_match($pattern, $serialized)) {
                    $securityFlags[] = [
                        'type' => "sensitive_data_{$type}",
                        'severity' => 'high',
                        'variable' => $name,
                    ];
                    $sanitized[$name] = '[REDACTED]';
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
