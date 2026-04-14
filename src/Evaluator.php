<?php

namespace TraceKit\PHP;

/**
 * Thrown when an expression requires server-side evaluation.
 */
class UnsupportedExpressionException extends \RuntimeException
{
}

/**
 * Evaluates portable-subset expressions locally to avoid server round-trips.
 *
 * Implements a custom recursive-descent parser for the TraceKit portable expression
 * grammar. Uses sandboxed evaluation (no eval/exec) per spec requirement T-162-05.
 *
 * Supported: comparison, logical, arithmetic, string concat (+), property access (dot/bracket),
 * membership (in), null safety, literals (string, int, float, bool, nil).
 *
 * Not supported (server-only): function calls, regex, assignment, array indexing,
 * ternary, range, template literals, bitwise.
 */
class Evaluator
{
    /**
     * Check if an expression can be evaluated locally by the SDK.
     * Returns false for server-only constructs.
     */
    public static function isSDKEvaluable(string $expression): bool
    {
        $expr = trim($expression);
        if ($expr === '') {
            return true;
        }

        // Function calls: word followed by opening paren
        if (preg_match('/\b[a-zA-Z_]\w*\s*\(/', $expr)) {
            return false;
        }

        // Regex match keyword
        if (preg_match('/\bmatches\b/', $expr)) {
            return false;
        }

        // Regex operator =~
        if (str_contains($expr, '=~')) {
            return false;
        }

        // Bitwise NOT ~ (but not inside =~, already handled)
        for ($i = 0; $i < strlen($expr); $i++) {
            if ($expr[$i] === '~' && ($i === 0 || $expr[$i - 1] !== '=')) {
                return false;
            }
        }

        // Bitwise AND: single & not part of &&
        for ($i = 0; $i < strlen($expr); $i++) {
            if ($expr[$i] === '&') {
                if ($i + 1 < strlen($expr) && $expr[$i + 1] === '&') {
                    $i++;
                    continue;
                }
                return false;
            }
        }

        // Bitwise OR: single | not part of ||
        for ($i = 0; $i < strlen($expr); $i++) {
            if ($expr[$i] === '|') {
                if ($i + 1 < strlen($expr) && $expr[$i + 1] === '|') {
                    $i++;
                    continue;
                }
                return false;
            }
        }

        // Bit shift
        if (str_contains($expr, '<<') || str_contains($expr, '>>')) {
            return false;
        }

        // Template literals
        if (str_contains($expr, '${')) {
            return false;
        }

        // Range operator
        if (str_contains($expr, '..')) {
            return false;
        }

        // Ternary
        if (str_contains($expr, '?')) {
            return false;
        }

        // Array indexing [N] (but not string bracket access ["key"])
        if (preg_match('/\[\d/', $expr)) {
            return false;
        }

        // Compound assignment
        if (preg_match('/[+\-*\/]=/', $expr)) {
            return false;
        }

        return true;
    }

    /**
     * Evaluate a condition expression. Empty condition returns true.
     * Throws UnsupportedExpressionException for server-only expressions.
     *
     * @throws UnsupportedExpressionException
     */
    public static function evaluateCondition(string $expression, array $env): bool
    {
        $expr = trim($expression);
        if ($expr === '') {
            return true;
        }

        if (!self::isSDKEvaluable($expr)) {
            throw new UnsupportedExpressionException(
                "Expression requires server-side evaluation: {$expr}"
            );
        }

        $result = self::evaluateExpression($expr, $env);

        if ($result === null) {
            return false;
        }

        if (is_bool($result)) {
            return $result;
        }

        return false;
    }

    /**
     * Evaluate an expression and return the raw result.
     * Returns null for empty expressions.
     * Throws UnsupportedExpressionException for server-only expressions.
     *
     * @throws UnsupportedExpressionException
     */
    public static function evaluateExpression(string $expression, array $env): mixed
    {
        $expr = trim($expression);
        if ($expr === '') {
            return null;
        }

        if (!self::isSDKEvaluable($expr)) {
            throw new UnsupportedExpressionException(
                "Expression requires server-side evaluation: {$expr}"
            );
        }

        $tokens = self::tokenize($expr);
        $pos = 0;
        $result = self::parseOr($tokens, $pos, $env);

        return $result;
    }

    /**
     * Evaluate multiple expressions. Returns map of expression => result.
     * On error, null is stored for that expression.
     */
    public static function evaluateExpressions(array $expressions, array $env): array
    {
        $results = [];
        foreach ($expressions as $expr) {
            try {
                $results[$expr] = self::evaluateExpression($expr, $env);
            } catch (\Throwable $e) {
                $results[$expr] = null;
            }
        }
        return $results;
    }

    // ---- Tokenizer ----

    private const TOKEN_NUMBER = 'NUMBER';
    private const TOKEN_STRING = 'STRING';
    private const TOKEN_BOOL = 'BOOL';
    private const TOKEN_NIL = 'NIL';
    private const TOKEN_IDENT = 'IDENT';
    private const TOKEN_OP = 'OP';
    private const TOKEN_LPAREN = 'LPAREN';
    private const TOKEN_RPAREN = 'RPAREN';
    private const TOKEN_LBRACKET = 'LBRACKET';
    private const TOKEN_RBRACKET = 'RBRACKET';
    private const TOKEN_DOT = 'DOT';
    private const TOKEN_IN = 'IN';

    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace
            if (ctype_space($expr[$i])) {
                $i++;
                continue;
            }

            // Two-character operators
            if ($i + 1 < $len) {
                $two = $expr[$i] . $expr[$i + 1];
                if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'])) {
                    $tokens[] = [self::TOKEN_OP, $two];
                    $i += 2;
                    continue;
                }
            }

            // Single-character operators
            if (in_array($expr[$i], ['<', '>', '+', '-', '*', '/', '!'])) {
                $tokens[] = [self::TOKEN_OP, $expr[$i]];
                $i++;
                continue;
            }

            // Parentheses
            if ($expr[$i] === '(') {
                $tokens[] = [self::TOKEN_LPAREN, '('];
                $i++;
                continue;
            }
            if ($expr[$i] === ')') {
                $tokens[] = [self::TOKEN_RPAREN, ')'];
                $i++;
                continue;
            }

            // Brackets
            if ($expr[$i] === '[') {
                $tokens[] = [self::TOKEN_LBRACKET, '['];
                $i++;
                continue;
            }
            if ($expr[$i] === ']') {
                $tokens[] = [self::TOKEN_RBRACKET, ']'];
                $i++;
                continue;
            }

            // Dot
            if ($expr[$i] === '.') {
                $tokens[] = [self::TOKEN_DOT, '.'];
                $i++;
                continue;
            }

            // String literal (double or single quoted)
            if ($expr[$i] === '"' || $expr[$i] === "'") {
                $quote = $expr[$i];
                $i++;
                $str = '';
                while ($i < $len && $expr[$i] !== $quote) {
                    if ($expr[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                        $str .= $expr[$i];
                    } else {
                        $str .= $expr[$i];
                    }
                    $i++;
                }
                if ($i < $len) {
                    $i++; // skip closing quote
                }
                $tokens[] = [self::TOKEN_STRING, $str];
                continue;
            }

            // Number (integer or float, including negative sign handled as unary op)
            if (ctype_digit($expr[$i])) {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                if (str_contains($num, '.')) {
                    $tokens[] = [self::TOKEN_NUMBER, (float) $num];
                } else {
                    $tokens[] = [self::TOKEN_NUMBER, (int) $num];
                }
                continue;
            }

            // Identifiers and keywords
            if (ctype_alpha($expr[$i]) || $expr[$i] === '_') {
                $ident = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $ident .= $expr[$i];
                    $i++;
                }

                if ($ident === 'true') {
                    $tokens[] = [self::TOKEN_BOOL, true];
                } elseif ($ident === 'false') {
                    $tokens[] = [self::TOKEN_BOOL, false];
                } elseif ($ident === 'nil' || $ident === 'null') {
                    $tokens[] = [self::TOKEN_NIL, null];
                } elseif ($ident === 'in') {
                    $tokens[] = [self::TOKEN_IN, 'in'];
                } else {
                    $tokens[] = [self::TOKEN_IDENT, $ident];
                }
                continue;
            }

            // Unknown character, skip
            $i++;
        }

        return $tokens;
    }

    // ---- Recursive Descent Parser ----
    // Precedence (low to high): || -> && -> == != -> < > <= >= -> + - -> * / -> unary ! - -> primary

    private static function parseOr(array &$tokens, int &$pos, array $env): mixed
    {
        $left = self::parseAnd($tokens, $pos, $env);

        while ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP && $tokens[$pos][1] === '||') {
            $pos++;
            $right = self::parseAnd($tokens, $pos, $env);
            $left = self::toBool($left) || self::toBool($right);
        }

        return $left;
    }

    private static function parseAnd(array &$tokens, int &$pos, array $env): mixed
    {
        $left = self::parseEquality($tokens, $pos, $env);

        while ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP && $tokens[$pos][1] === '&&') {
            $pos++;
            $right = self::parseEquality($tokens, $pos, $env);
            $left = self::toBool($left) && self::toBool($right);
        }

        return $left;
    }

    private static function parseEquality(array &$tokens, int &$pos, array $env): mixed
    {
        $left = self::parseComparison($tokens, $pos, $env);

        while ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP
            && in_array($tokens[$pos][1], ['==', '!='])) {
            $op = $tokens[$pos][1];
            $pos++;
            $right = self::parseComparison($tokens, $pos, $env);
            $left = self::evalEquality($op, $left, $right);
        }

        return $left;
    }

    private static function parseComparison(array &$tokens, int &$pos, array $env): mixed
    {
        $left = self::parseAddSub($tokens, $pos, $env);

        while ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP
            && in_array($tokens[$pos][1], ['<', '>', '<=', '>='])) {
            $op = $tokens[$pos][1];
            $pos++;
            $right = self::parseAddSub($tokens, $pos, $env);
            $left = self::evalComparison($op, $left, $right);
        }

        return $left;
    }

    private static function parseAddSub(array &$tokens, int &$pos, array $env): mixed
    {
        $left = self::parseMulDiv($tokens, $pos, $env);

        while ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP
            && in_array($tokens[$pos][1], ['+', '-'])) {
            $op = $tokens[$pos][1];
            $pos++;
            $right = self::parseMulDiv($tokens, $pos, $env);

            if ($op === '+') {
                // String concatenation if either operand is a string
                if (is_string($left) || is_string($right)) {
                    $left = (string) $left . (string) $right;
                } else {
                    $left = self::toNum($left) + self::toNum($right);
                }
            } else {
                $left = self::toNum($left) - self::toNum($right);
            }
        }

        return $left;
    }

    private static function parseMulDiv(array &$tokens, int &$pos, array $env): mixed
    {
        $left = self::parseUnary($tokens, $pos, $env);

        while ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP
            && in_array($tokens[$pos][1], ['*', '/'])) {
            $op = $tokens[$pos][1];
            $pos++;
            $right = self::parseUnary($tokens, $pos, $env);

            if ($op === '*') {
                $left = self::toNum($left) * self::toNum($right);
            } else {
                $rNum = self::toNum($right);
                $left = ($rNum == 0) ? null : self::toNum($left) / $rNum;
            }
        }

        return $left;
    }

    private static function parseUnary(array &$tokens, int &$pos, array $env): mixed
    {
        if ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_OP) {
            if ($tokens[$pos][1] === '!') {
                $pos++;
                $val = self::parseUnary($tokens, $pos, $env);
                return !self::toBool($val);
            }
            if ($tokens[$pos][1] === '-') {
                $pos++;
                $val = self::parseUnary($tokens, $pos, $env);
                return -self::toNum($val);
            }
        }

        return self::parsePrimary($tokens, $pos, $env);
    }

    private static function parsePrimary(array &$tokens, int &$pos, array $env): mixed
    {
        if ($pos >= count($tokens)) {
            return null;
        }

        $token = $tokens[$pos];

        // Parenthesized expression
        if ($token[0] === self::TOKEN_LPAREN) {
            $pos++;
            $val = self::parseOr($tokens, $pos, $env);
            // consume )
            if ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_RPAREN) {
                $pos++;
            }
            // After a parenthesized group, check for "in" operator
            // (not typical but handle it for completeness)
            return self::parsePostfix($val, $tokens, $pos, $env);
        }

        // Literals
        if ($token[0] === self::TOKEN_NUMBER) {
            $pos++;
            return $token[1];
        }
        if ($token[0] === self::TOKEN_STRING) {
            $pos++;
            $val = $token[1];
            // Check for "in" operator: "key" in map
            if ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_IN) {
                $pos++;
                $mapVal = self::parsePrimary($tokens, $pos, $env);
                if (is_array($mapVal)) {
                    return array_key_exists($val, $mapVal);
                }
                return false;
            }
            return $val;
        }
        if ($token[0] === self::TOKEN_BOOL) {
            $pos++;
            return $token[1];
        }
        if ($token[0] === self::TOKEN_NIL) {
            $pos++;
            return null;
        }

        // Identifier (variable reference)
        if ($token[0] === self::TOKEN_IDENT) {
            $pos++;
            $name = $token[1];

            // Resolve from environment
            $val = $env[$name] ?? null;

            // Follow dot/bracket access chain
            $val = self::parsePostfix($val, $tokens, $pos, $env);

            return $val;
        }

        // Fallback
        $pos++;
        return null;
    }

    /**
     * Parse postfix operations: dot access, bracket access
     */
    private static function parsePostfix(mixed $val, array &$tokens, int &$pos, array $env): mixed
    {
        while ($pos < count($tokens)) {
            if ($tokens[$pos][0] === self::TOKEN_DOT) {
                $pos++;
                if ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_IDENT) {
                    $key = $tokens[$pos][1];
                    $pos++;
                    $val = self::nullSafeAccess($val, $key);
                } else {
                    break;
                }
            } elseif ($tokens[$pos][0] === self::TOKEN_LBRACKET) {
                $pos++;
                // Parse the key expression inside brackets
                if ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_STRING) {
                    $key = $tokens[$pos][1];
                    $pos++;
                    $val = self::nullSafeAccess($val, $key);
                }
                // consume ]
                if ($pos < count($tokens) && $tokens[$pos][0] === self::TOKEN_RBRACKET) {
                    $pos++;
                }
            } else {
                break;
            }
        }

        return $val;
    }

    /**
     * Null-safe property/key access. Returns null if base is null or key missing.
     */
    private static function nullSafeAccess(mixed $val, string $key): mixed
    {
        if ($val === null) {
            return null;
        }
        if (is_array($val)) {
            return $val[$key] ?? null;
        }
        if (is_object($val)) {
            return $val->$key ?? null;
        }
        return null;
    }

    /**
     * Convert value to boolean for logical operations.
     */
    private static function toBool(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }
        if ($val === null) {
            return false;
        }
        return (bool) $val;
    }

    /**
     * Convert value to number for arithmetic operations.
     */
    private static function toNum(mixed $val): int|float
    {
        if (is_int($val) || is_float($val)) {
            return $val;
        }
        if ($val === null) {
            return 0;
        }
        if (is_numeric($val)) {
            return $val + 0;
        }
        return 0;
    }

    /**
     * Evaluate equality operators with spec type rules.
     * nil == nil is true. No implicit type coercion except int/float promotion.
     */
    private static function evalEquality(string $op, mixed $left, mixed $right): bool
    {
        // Handle int/float promotion: 42 == 42.0 should be true
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            $cmp = ((float) $left == (float) $right);
            return $op === '==' ? $cmp : !$cmp;
        }

        // Strict comparison for everything else (no cross-type coercion)
        // nil == nil: true. nil == false: false. "42" == 42: false.
        if ($left === null && $right === null) {
            return $op === '==';
        }

        // Different types (except int/float handled above): always not equal
        if (gettype($left) !== gettype($right)) {
            return $op === '!=';
        }

        $cmp = ($left === $right);
        return $op === '==' ? $cmp : !$cmp;
    }

    /**
     * Evaluate comparison operators. Mixed-type comparisons return false.
     */
    private static function evalComparison(string $op, mixed $left, mixed $right): bool
    {
        // Both numeric
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return match ($op) {
                '<'  => $left < $right,
                '>'  => $left > $right,
                '<=' => $left <= $right,
                '>=' => $left >= $right,
                default => false,
            };
        }

        // Both strings
        if (is_string($left) && is_string($right)) {
            return match ($op) {
                '<'  => $left < $right,
                '>'  => $left > $right,
                '<=' => $left <= $right,
                '>=' => $left >= $right,
                default => false,
            };
        }

        // Incompatible types
        return false;
    }
}
