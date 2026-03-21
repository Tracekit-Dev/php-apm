# Changelog

All notable changes to the TraceKit PHP SDK will be documented in this file.

## 1.3.0 - 2026-03-21

### Added
- LLM auto-instrumentation for OpenAI and Anthropic APIs via Guzzle middleware
- Streaming support for both OpenAI (SSE) and Anthropic (SSE) chat completions
- Automatic capture of gen_ai.* semantic convention attributes (model, provider, tokens, cost, latency, finish_reason)
- Content capture option for request/response messages (`capture_content` config)
- Tool call detection and instrumentation for function calling
- PII scrubbing for captured content
- Provider auto-detection by HTTP request host

### Changed
- TracekitClient now accepts `llm` config key for LLM instrumentation setup

## 1.2.1 - 2026-03-21

### Fixed
- Only link snapshots to sampled traces

## 1.2.0 - 2026-03-21

### Added
- PII scrubbing, kill switch, circuit breaker

## 1.1.0

### Added
- Metrics support (counter, gauge, histogram)

## 1.0.6

### Fixed
- Bug fixes and stability improvements
