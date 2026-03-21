<?php

namespace TraceKit\PHP\Tests\Integrations;

use PHPUnit\Framework\TestCase;
use TraceKit\PHP\Integrations\LlmCommon;

/**
 * Unit tests for LlmCommon shared helpers.
 *
 * PII test vectors sourced from docs/genai-pii-scrubbing-fixtures.json
 * to ensure cross-SDK consistency.
 */
class LlmCommonTest extends TestCase
{
    // ------------------------------------------------------------------
    // PII Key Scrubbing (from key_scrubbing_tests fixtures)
    // ------------------------------------------------------------------

    public function testScrubPiiPasswordField(): void
    {
        $input = '{"username": "john", "password": "secret123"}';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('john', $result);
        $this->assertStringNotContainsString('secret123', $result);
    }

    public function testScrubPiiApiKeyField(): void
    {
        $input = '{"api_key": "sk-abc123", "model": "gpt-4o"}';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('gpt-4o', $result);
        $this->assertStringNotContainsString('sk-abc123', $result);
    }

    public function testScrubPiiNestedSecretField(): void
    {
        $input = '{"config": {"secret": "mysecret", "name": "test"}}';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('test', $result);
        $this->assertStringNotContainsString('mysecret', $result);
    }

    public function testScrubPiiCredentialField(): void
    {
        $input = '{"credential": "abc123", "user": "alice"}';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('alice', $result);
        $this->assertStringNotContainsString('"abc123"', $result);
    }

    public function testScrubPiiTokenField(): void
    {
        $input = '{"token": "tok_xyz", "type": "bearer"}';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('bearer', $result);
        $this->assertStringNotContainsString('tok_xyz', $result);
    }

    public function testScrubPiiNoSensitiveKeys(): void
    {
        $input = '{"model": "gpt-4o", "messages": [{"role": "user", "content": "hello"}]}';
        $result = LlmCommon::scrubPII($input);

        $this->assertEquals($input, $result);
    }

    // ------------------------------------------------------------------
    // PII Pattern Scrubbing (from pattern_scrubbing_tests fixtures)
    // ------------------------------------------------------------------

    public function testScrubPiiEmail(): void
    {
        $input = 'Contact me at user@example.com for details';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('user@example.com', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('Contact me at', $result);
    }

    public function testScrubPiiSsn(): void
    {
        $input = 'SSN: 123-45-6789';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('123-45-6789', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiCreditCard(): void
    {
        $input = 'Card: 4111-1111-1111-1111';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('4111-1111-1111-1111', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiAwsKey(): void
    {
        $input = 'Key: AKIAIOSFODNN7EXAMPLE';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiStripeKey(): void
    {
        $input = 'Stripe key: sk_live_abc123def456ghi';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('sk_live_abc123def456ghi', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiJwt(): void
    {
        $input = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiPrivateKey(): void
    {
        $input = "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA...";
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('-----BEGIN RSA PRIVATE KEY-----', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiNoPatternMatch(): void
    {
        $input = 'The weather is nice today in San Francisco';
        $result = LlmCommon::scrubPII($input);

        $this->assertEquals($input, $result);
    }

    public function testScrubPiiMixedContent(): void
    {
        $input = 'Please send the report to admin@company.com and include the Q4 numbers';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('admin@company.com', $result);
        $this->assertStringContainsString('Q4 numbers', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    // ------------------------------------------------------------------
    // Combined Key + Pattern Scrubbing (from combined_tests fixtures)
    // ------------------------------------------------------------------

    public function testScrubPiiCombinedKeyAndPattern(): void
    {
        $input = '{"password": "secret", "message": "Contact user@test.com"}';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('"secret"', $result);
        $this->assertStringNotContainsString('user@test.com', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testScrubPiiRealisticLlmMessageWithPii(): void
    {
        $input = '[{"role": "user", "content": "My SSN is 123-45-6789 and my email is test@example.com"}]';
        $result = LlmCommon::scrubPII($input);

        $this->assertStringNotContainsString('123-45-6789', $result);
        $this->assertStringNotContainsString('test@example.com', $result);
        $this->assertStringContainsString('role', $result);
        $this->assertStringContainsString('user', $result);
        $this->assertStringContainsString('content', $result);
    }

    // ------------------------------------------------------------------
    // Provider Detection
    // ------------------------------------------------------------------

    public function testDetectProviderOpenAi(): void
    {
        $this->assertEquals('openai', LlmCommon::detectProvider('api.openai.com'));
    }

    public function testDetectProviderAnthropic(): void
    {
        $this->assertEquals('anthropic', LlmCommon::detectProvider('api.anthropic.com'));
    }

    public function testDetectProviderUnknown(): void
    {
        $this->assertNull(LlmCommon::detectProvider('api.example.com'));
    }

    public function testDetectProviderWithPort(): void
    {
        $this->assertEquals('openai', LlmCommon::detectProvider('api.openai.com:443'));
    }

    public function testDetectProviderCaseInsensitive(): void
    {
        $this->assertEquals('openai', LlmCommon::detectProvider('API.OPENAI.COM'));
    }

    // ------------------------------------------------------------------
    // Content Capture Config
    // ------------------------------------------------------------------

    public function testShouldCaptureContentDefault(): void
    {
        // Ensure env var is clean
        putenv('TRACEKIT_LLM_CAPTURE_CONTENT');

        $this->assertFalse(LlmCommon::shouldCaptureContent([]));
    }

    public function testShouldCaptureContentDisabledExplicitly(): void
    {
        putenv('TRACEKIT_LLM_CAPTURE_CONTENT');

        $this->assertFalse(LlmCommon::shouldCaptureContent(['capture_content' => false]));
    }

    public function testShouldCaptureContentEnabled(): void
    {
        putenv('TRACEKIT_LLM_CAPTURE_CONTENT');

        $this->assertTrue(LlmCommon::shouldCaptureContent(['capture_content' => true]));
    }

    public function testShouldCaptureContentEnvOverrideTrueOverridesConfigFalse(): void
    {
        putenv('TRACEKIT_LLM_CAPTURE_CONTENT=true');

        $this->assertTrue(LlmCommon::shouldCaptureContent(['capture_content' => false]));
    }

    public function testShouldCaptureContentEnvOverrideFalseOverridesConfigTrue(): void
    {
        putenv('TRACEKIT_LLM_CAPTURE_CONTENT=false');

        $this->assertFalse(LlmCommon::shouldCaptureContent(['capture_content' => true]));
    }

    protected function tearDown(): void
    {
        // Always clean up the env var after each test
        putenv('TRACEKIT_LLM_CAPTURE_CONTENT');
    }
}
