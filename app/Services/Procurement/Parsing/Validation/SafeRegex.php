<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Validation;

use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Exceptions\Procurement\Parsing\ParsingRegexExecutionException;
use InvalidArgumentException;

/**
 * Валидатор и безопасный исполнитель регулярных выражений с защитой от ReDoS.
 */
final class SafeRegex
{
    private const int MAX_PATTERN_LENGTH = 512;

    private const int MAX_SUBJECT_BYTES = 65536;

    private const string ALLOWED_FLAGS = 'uismD';

    private const string PCRE_BACKTRACK_LIMIT = '50000';

    private const string PCRE_RECURSION_LIMIT = '256';

    public static function assertSafe(string $pattern, ?int $minCaptureGroups = null): void
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new InvalidArgumentException('Regex pattern must be non-empty and at most 512 bytes.');
        }

        $delimiter = $pattern[0];
        if ($delimiter !== '/' && $delimiter !== '~') {
            throw new InvalidArgumentException('Regex pattern must use / or ~ delimiter.');
        }

        $lastDelimiterPos = strrpos($pattern, $delimiter);
        if ($lastDelimiterPos === false || $lastDelimiterPos === 0) {
            throw new InvalidArgumentException('Regex pattern must have a closing delimiter.');
        }

        $body = substr($pattern, 1, $lastDelimiterPos - 1);
        $flags = substr($pattern, $lastDelimiterPos + 1);

        if ($body === '') {
            throw new InvalidArgumentException('Regex pattern body must not be empty.');
        }

        if ($flags !== '' && preg_match('/^['.self::ALLOWED_FLAGS.']+$/', $flags) !== 1) {
            throw new InvalidArgumentException('Regex pattern contains disallowed flags (allowed: u, i, s, m, D).');
        }

        $captureGroups = self::scanPatternBody($body, $delimiter);

        if ($minCaptureGroups !== null && $captureGroups < $minCaptureGroups) {
            throw new InvalidArgumentException(sprintf(
                'Regex pattern must contain at least %d capture group(s), %d found.',
                $minCaptureGroups,
                $captureGroups,
            ));
        }

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException('Regex pattern failed PCRE compilation.');
        }
    }

    /**
     * @param  array<int|string, mixed>  $matches
     *
     * @param-out array<int|string, mixed> $matches
     */
    public static function match(RegexPattern $pattern, string $subject, array &$matches = [], int $flags = 0): bool
    {
        if (strlen($subject) > self::MAX_SUBJECT_BYTES) {
            throw new ParsingRegexExecutionException('Regex subject exceeds maximum allowed length of 65536 bytes.');
        }

        $prevBacktrack = ini_set('pcre.backtrack_limit', self::PCRE_BACKTRACK_LIMIT);
        $prevRecursion = ini_set('pcre.recursion_limit', self::PCRE_RECURSION_LIMIT);

        try {
            /** @var int-mask<0, 256, 512> $pcreFlags */
            $pcreFlags = $flags;
            $result = @preg_match($pattern->pattern, $subject, $matches, $pcreFlags);
            $lastError = preg_last_error();

            if ($result === false || $lastError !== PREG_NO_ERROR) {
                throw new ParsingRegexExecutionException(sprintf(
                    'Regex match failed with PCRE error code %d.',
                    $lastError,
                ));
            }

            return $result === 1;
        } finally {
            if ($prevBacktrack !== false) {
                ini_set('pcre.backtrack_limit', $prevBacktrack);
            }
            if ($prevRecursion !== false) {
                ini_set('pcre.recursion_limit', $prevRecursion);
            }
        }
    }

    public static function replace(RegexPattern $pattern, string $replacement, string $subject): string
    {
        if (strlen($subject) > self::MAX_SUBJECT_BYTES) {
            throw new ParsingRegexExecutionException('Regex subject exceeds maximum allowed length of 65536 bytes.');
        }

        $prevBacktrack = ini_set('pcre.backtrack_limit', self::PCRE_BACKTRACK_LIMIT);
        $prevRecursion = ini_set('pcre.recursion_limit', self::PCRE_RECURSION_LIMIT);

        try {
            $result = @preg_replace($pattern->pattern, $replacement, $subject);
            $lastError = preg_last_error();

            if ($result === null || $lastError !== PREG_NO_ERROR) {
                throw new ParsingRegexExecutionException(sprintf(
                    'Regex replace failed with PCRE error code %d.',
                    $lastError,
                ));
            }

            return $result;
        } finally {
            if ($prevBacktrack !== false) {
                ini_set('pcre.backtrack_limit', $prevBacktrack);
            }
            if ($prevRecursion !== false) {
                ini_set('pcre.recursion_limit', $prevRecursion);
            }
        }
    }

    private static function scanPatternBody(string $body, string $delimiter): int
    {
        if (preg_match('/(?:^|[^\\\\])(?:\\\\\\\\)*\(\*/', $body) === 1) {
            throw new InvalidArgumentException('Regex pattern must not contain PCRE control verbs (*...).');
        }

        if (preg_match('/(?:^|[^\\\\])(?:\\\\\\\\)*\\\\(?:[1-9]|k[<{\']|g[<{0-9-])/', $body) === 1) {
            throw new InvalidArgumentException('Regex pattern must not contain backreferences.');
        }

        if (preg_match('/(?:^|[^\\\\])(?:\\\\\\\\)*\(\?(?:R|0|&)/', $body) === 1) {
            throw new InvalidArgumentException('Regex pattern must not contain recursive subpattern calls.');
        }

        if (preg_match('/\.\*.*\.\*|\.\+.*\.\+/', $body) === 1) {
            throw new InvalidArgumentException('Regex pattern contains consecutive unbounded wildcards.');
        }

        if (preg_match('/\([^()]*\|[^()]*\)[+*?{]/', $body) === 1) {
            throw new InvalidArgumentException('Regex pattern contains quantified alternation group.');
        }

        $length = strlen($body);
        $inClass = false;
        $captureGroups = 0;
        /** @var list<array{hasQuantifier: bool, isCapturing: bool}> $groupStack */
        $groupStack = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                }

                continue;
            }

            if ($char === $delimiter) {
                throw new InvalidArgumentException('Regex pattern contains an unescaped delimiter.');
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char === '(') {
                $isCapturing = true;
                if ($i + 1 < $length && $body[$i + 1] === '?') {
                    $isCapturing = $i + 2 < $length && $body[$i + 2] === '<'
                        && ($i + 3 >= $length || ($body[$i + 3] !== '=' && $body[$i + 3] !== '!'));
                }
                if ($isCapturing) {
                    $captureGroups++;
                }
                $groupStack[] = ['hasQuantifier' => false, 'isCapturing' => $isCapturing];

                continue;
            }

            if ($char === ')') {
                if ($groupStack === []) {
                    throw new InvalidArgumentException('Regex pattern has unbalanced closing parenthesis.');
                }
                $closed = array_pop($groupStack);
                $next = $i + 1 < $length ? $body[$i + 1] : '';
                $groupQuantified = $next === '+' || $next === '*' || $next === '?' || $next === '{';

                if ($groupQuantified && $closed['hasQuantifier']) {
                    throw new InvalidArgumentException('Regex pattern must not contain nested quantifiers.');
                }

                if ($groupQuantified && $groupStack !== []) {
                    $topIndex = count($groupStack) - 1;
                    $groupStack[$topIndex]['hasQuantifier'] = true;
                }

                continue;
            }

            if ($char === '+' || $char === '*' || $char === '{') {
                if ($groupStack !== []) {
                    $topIndex = count($groupStack) - 1;
                    $groupStack[$topIndex]['hasQuantifier'] = true;
                }
            }
        }

        return $captureGroups;
    }
}
