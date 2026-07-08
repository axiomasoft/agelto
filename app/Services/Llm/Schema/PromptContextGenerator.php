<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use ReflectionClass;

/**
 * Собирает очищенный PHP-исходник Data-контрактов для системной инструкции LLM (приём v1,
 * Old/app/Services/PromptService.php::getCleanSchema; docs/specs/v2/08-estimate-extraction.md §6).
 * Модель читает докблоки/#[Alias]-атрибуты как инструкции, а не исполняемый код — поэтому
 * `<?php`/namespace/use/declare вырезаются, вывод предваряется комментарием с именем файла.
 * Выход обязан быть байт-в-байт стабильным между запусками (база rules_hash и implicit-cache
 * префикса, research/E §1) — сортировка классов детерминирована, обёртки шага (суффикс
 * `Response`) всегда идут последними (композиция v1, D5).
 */
final readonly class PromptContextGenerator
{
    /**
     * @param  class-string[]  $classes
     */
    public function generate(array $classes): string
    {
        [$plain, $responses] = $this->partitionByKind($classes);

        $ordered = [...$this->sortByShortName($plain), ...$this->sortByShortName($responses)];

        $blocks = array_map(
            fn (string $class): string => $this->cleanSource($class),
            $ordered,
        );

        return implode("\n\n", $blocks);
    }

    /**
     * sha1 системной части промта — фиксирует версию контракта (`prc_ai_prompts.rules_hash`).
     */
    public function rulesHash(string $systemPart): string
    {
        return sha1($systemPart);
    }

    /**
     * @param  class-string[]  $classes
     * @return array{0: class-string[], 1: class-string[]}
     */
    private function partitionByKind(array $classes): array
    {
        $plain = [];
        $responses = [];

        foreach (array_unique($classes) as $class) {
            if (str_ends_with($this->shortName($class), 'Response')) {
                $responses[] = $class;

                continue;
            }

            $plain[] = $class;
        }

        return [$plain, $responses];
    }

    /**
     * @param  class-string[]  $classes
     * @return class-string[]
     */
    private function sortByShortName(array $classes): array
    {
        usort($classes, fn (string $a, string $b): int => $this->shortName($a) <=> $this->shortName($b));

        return $classes;
    }

    private function shortName(string $class): string
    {
        return new ReflectionClass($class)->getShortName();
    }

    /**
     * @param  class-string  $class
     */
    private function cleanSource(string $class): string
    {
        $path = new ReflectionClass($class)->getFileName();

        if ($path === false) {
            throw new \RuntimeException("Не удалось определить путь исходника для {$class}.");
        }

        $content = file_get_contents($path);

        $patterns = [
            '/<\?php/i',
            '/declare\s*\([^)]+\);/i',
            '/namespace\s+[^;]+;/i',
            '/use\s+[^;]+;/i',
        ];

        $clean = mb_trim((string) preg_replace($patterns, '', $content));

        return "// --- Source: {$this->basename($path)} ---\n{$clean}";
    }

    private function basename(string $path): string
    {
        return basename($path);
    }
}
