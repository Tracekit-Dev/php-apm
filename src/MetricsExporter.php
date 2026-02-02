<?php

namespace TraceKit\PHP;

/**
 * MetricsExporter sends metrics to backend in OTLP format
 */
class MetricsExporter
{
    private string $endpoint;
    private string $apiKey;
    private string $serviceName;

    public function __construct(string $endpoint, string $apiKey, string $serviceName)
    {
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->serviceName = $serviceName;
    }

    public function export(array $dataPoints): void
    {
        if (empty($dataPoints)) {
            return;
        }

        $payload = $this->toOTLP($dataPoints);
        $jsonBody = json_encode($payload);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("HTTP $httpCode");
        }
    }

    private function toOTLP(array $dataPoints): array
    {
        // Group by name and type
        $grouped = [];
        foreach ($dataPoints as $dp) {
            $key = $dp['name'] . ':' . $dp['type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $dp;
        }

        // Build metrics array
        $metrics = [];

        foreach ($grouped as $key => $dps) {
            list($name, $type) = explode(':', $key, 2);

            // Convert data points
            $otlpDataPoints = [];
            foreach ($dps as $dp) {
                $attributes = [];
                foreach ($dp['tags'] as $k => $v) {
                    $attributes[] = [
                        'key' => $k,
                        'value' => ['stringValue' => $v]
                    ];
                }

                $otlpDataPoints[] = [
                    'attributes' => $attributes,
                    'timeUnixNano' => (string)((int)($dp['timestamp'] * 1_000_000_000)),
                    'asDouble' => $dp['value']
                ];
            }

            // Create metric based on type
            if ($type === 'counter') {
                $metric = [
                    'name' => $name,
                    'sum' => [
                        'dataPoints' => $otlpDataPoints,
                        'aggregationTemporality' => 2, // DELTA
                        'isMonotonic' => true
                    ]
                ];
            } else { // gauge or histogram
                $metric = [
                    'name' => $name,
                    'gauge' => [
                        'dataPoints' => $otlpDataPoints
                    ]
                ];
            }

            $metrics[] = $metric;
        }

        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $this->serviceName]
                            ]
                        ]
                    ],
                    'scopeMetrics' => [
                        [
                            'scope' => [
                                'name' => 'tracekit'
                            ],
                            'metrics' => $metrics
                        ]
                    ]
                ]
            ]
        ];
    }
}
