<?php

namespace TraceKit\PHP\Tests;

use PHPUnit\Framework\TestCase;
use TraceKit\PHP\Evaluator;
use TraceKit\PHP\UnsupportedExpressionException;

class EvaluatorTest extends TestCase
{
    private static array $fixtures;
    private static array $defaultVars;

    public static function setUpBeforeClass(): void
    {
        $fixtureFile = __DIR__ . '/../testdata/expression_fixtures.json';
        $json = json_decode(file_get_contents($fixtureFile), true);
        self::$fixtures = $json['test_cases'];
        self::$defaultVars = $json['default_variables'];
    }

    public function fixtureProvider(): array
    {
        $fixtureFile = __DIR__ . '/../testdata/expression_fixtures.json';
        $json = json_decode(file_get_contents($fixtureFile), true);
        $cases = [];
        foreach ($json['test_cases'] as $tc) {
            $cases[$tc['id'] . ': ' . $tc['description']] = [$tc];
        }
        return $cases;
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testFixtureCase(array $tc): void
    {
        $vars = $tc['variables'] ?? self::$defaultVars;

        if ($tc['classify'] === 'server-only') {
            // Server-only expressions must be classified as not SDK-evaluable
            $this->assertFalse(
                Evaluator::isSDKEvaluable($tc['expression']),
                "Expression '{$tc['expression']}' should be classified as server-only"
            );
            return;
        }

        // SDK-evaluable expressions
        $this->assertTrue(
            Evaluator::isSDKEvaluable($tc['expression']),
            "Expression '{$tc['expression']}' should be classified as sdk-evaluable"
        );

        $result = Evaluator::evaluateExpression($tc['expression'], $vars);

        if ($tc['expected'] === null) {
            $this->assertNull($result, "Expression '{$tc['expression']}' should return null");
        } elseif (is_bool($tc['expected'])) {
            $this->assertSame($tc['expected'], $result, "Expression '{$tc['expression']}' failed");
        } elseif (is_int($tc['expected'])) {
            // Allow int or float match for integer expected values
            if (is_float($result) && floor($result) == $result) {
                $this->assertSame($tc['expected'], (int) $result, "Expression '{$tc['expression']}' failed");
            } else {
                $this->assertSame($tc['expected'], $result, "Expression '{$tc['expression']}' failed");
            }
        } elseif (is_float($tc['expected'])) {
            $this->assertEqualsWithDelta($tc['expected'], $result, 0.001, "Expression '{$tc['expression']}' failed");
        } else {
            $this->assertSame($tc['expected'], $result, "Expression '{$tc['expression']}' failed");
        }
    }

    public function testIsSDKEvaluableBasic(): void
    {
        $this->assertTrue(Evaluator::isSDKEvaluable('status == 200'));
        $this->assertFalse(Evaluator::isSDKEvaluable("matches(path, '/api')"));
        $this->assertFalse(Evaluator::isSDKEvaluable("len(user.profile.tags) > 1"));
        $this->assertFalse(Evaluator::isSDKEvaluable("contains(user.email, \"@\")"));
    }

    public function testEvaluateConditionEmpty(): void
    {
        $this->assertTrue(Evaluator::evaluateCondition('', []));
    }

    public function testEvaluateConditionUnsupported(): void
    {
        $this->expectException(UnsupportedExpressionException::class);
        Evaluator::evaluateCondition("matches(path, '/api')", ['path' => '/api']);
    }

    public function testEvaluateConditionTrue(): void
    {
        $this->assertTrue(Evaluator::evaluateCondition('status == 200', ['status' => 200]));
    }

    public function testEvaluateConditionFalse(): void
    {
        $this->assertFalse(Evaluator::evaluateCondition('status == 500', ['status' => 200]));
    }

    public function testEvaluateExpressions(): void
    {
        $env = ['status' => 200, 'method' => 'GET'];
        $results = Evaluator::evaluateExpressions(['status', 'method'], $env);
        $this->assertSame(200, $results['status']);
        $this->assertSame('GET', $results['method']);
    }

    public function testNullSafePropertyAccess(): void
    {
        $env = self::$defaultVars;
        // user.settings is null, accessing .theme should return null
        $result = Evaluator::evaluateExpression('user.settings.theme == nil', $env);
        $this->assertTrue($result);
    }
}
