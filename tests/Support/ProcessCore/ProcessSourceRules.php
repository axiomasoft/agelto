<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Tests\Support\Architecture\PlatformSourceRules;

/** Scoped T44 source checks. This does not prove transaction or single-writer behavior. */
final class ProcessSourceRules
{
    /** @var list<string> Exact application composition seams, never a whole namespace bypass. */
    private const array COMPOSITION = [
        'app/Services/Procurement/Process/StageRegistry.php',
        'app/Services/Procurement/Process/SubjectLockResolver.php',
    ];

    /** @var list<string> Codec dispatch is serialization, not runtime handler selection. */
    private const array CODECS = [
        'app/Services/Procurement/Process/ProcessPayloadCodec.php',
        'app/Services/Procurement/Process/WorkInputCodec.php',
        'app/Services/Procurement/Process/Contracts/WorkInputCodec.php',
    ];

    /** @return list<string> */
    public static function violations(string $path, string $source): array
    {
        $violations = [];
        if (preg_match('/^declare\(strict_types=1\);/m', $source) !== 1) {
            $violations[] = 'strict types required: '.$path;
        }
        $parsed = PlatformSourceRules::inspect($source);
        if ($parsed['group_use']) {
            $violations[] = 'group use is not supported: '.$path;
        }
        if (! in_array($path, self::COMPOSITION, true)) {
            foreach ($parsed['dependencies'] as $dependency) {
                if (str_contains($dependency, '\\Platforms\\')
                    || str_starts_with($dependency, 'App\\Services\\Llm\\Providers\\')
                    || str_starts_with($dependency, 'App\\Services\\Procurement\\Analyzers\\')
                    || str_starts_with($dependency, 'App\\Services\\Procurement\\Extraction\\')
                    || str_starts_with($dependency, 'App\\Services\\Procurement\\Screening\\')) {
                    $violations[] = 'forbidden process dependency: '.$path.' -> '.$dependency;
                }
            }
        }
        if (preg_match('~^app/(?:Services|Repositories)/Procurement/Process/~', $path) === 1
            && ! in_array($path, [...self::COMPOSITION, ...self::CODECS], true)
            && self::dispatchesByStage($source)) {
            $violations[] = 'stage handler branching: '.$path;
        }
        if (preg_match('~^app/Dto/Procurement/(?:Process|Platform|Acquisition)/~', $path) === 1) {
            $name = basename($path, '.php');
            // ExecutionContext owns mutable heartbeat/checkpoint state. Spatie Data has its own contract.
            $mutable = in_array($name, ['ExecutionContext', 'ProcessValue'], true)
                || in_array('Spatie\\LaravelData\\Data', $parsed['dependencies'], true);
            if (! $mutable && preg_match('/\bclass\s+/', $source) === 1
                && preg_match('/\bfinal\s+readonly\s+class\s+/', $source) !== 1) {
                $violations[] = 'value type must be final readonly: '.$path;
            }
        }

        return $violations;
    }

    private static function dispatchesByStage(string $source): bool
    {
        $aliases = ['StageCode'];
        if (preg_match_all('/use\s+([^;]+);/i', $source, $matches) > 0) {
            foreach ($matches[1] as $import) {
                if (str_starts_with($import, 'App\\Enums\\Procurement\\Process\\StageCode')
                    && preg_match('/\s+as\s+(\w+)$/i', $import, $alias) === 1) {
                    $aliases[] = $alias[1];
                }
            }
        }
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || ! in_array($token[0], [T_IF, T_ELSEIF, T_MATCH, T_SWITCH], true)) {
                continue;
            }
            $expression = '';
            $depth = 0;
            $opened = false;
            while (++$index < $count) {
                $part = $tokens[$index];
                $text = is_array($part) ? $part[1] : $part;
                if (is_array($part) && in_array($part[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $expression .= $text;
                if ($text === '(') {
                    $opened = true;
                    $depth++;
                } elseif ($text === ')' && --$depth === 0 && $opened) {
                    break;
                }
            }
            if (preg_match('/(?:->|\$)\s*stage\b/i', $expression) === 1) {
                return true;
            }
            foreach ($aliases as $alias) {
                if (preg_match('/\b'.preg_quote($alias, '/').'\s*::/', $expression) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
