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
 *
 * Печатается ТРАНЗИТИВНОЕ ЗАМЫКАНИЕ переданных классов (D51, P6.1), а не переданный список: до
 * P6.1 боевой call-site передавал одну обёртку ответа шага, и модель на `extraction:rows`
 * получала `RowsChunkResponse` без единого докблока `EstimateRow`/`RowResource`/`RowAttributes`.
 * Правило «что вложено» — общее с JSON-схемой (`DataTypeGraph`); словари, до которых обход не
 * достаёт, объявляет сам ТИП и передаёт их в этот список явно.
 */
final readonly class PromptContextGenerator
{
    public function __construct(
        private DataTypeGraph $types = new DataTypeGraph,
    ) {}

    /**
     * @param  class-string[]  $classes  корни блока схем: обёртки ответа шага и словари типа.
     */
    public function generate(array $classes): string
    {
        [$plain, $responses] = $this->partitionByKind($this->expand($classes));

        $ordered = [...$this->sortByShortName($plain), ...$this->sortByShortName($responses)];

        $blocks = array_map(
            fn (string $class): string => $this->cleanSource($class),
            $ordered,
        );

        return implode("\n\n", $blocks);
    }

    /**
     * sha1 системной части промта — фиксирует версию контракта (`llm_prompts.rules_hash`).
     */
    public function rulesHash(string $systemPart): string
    {
        return sha1($systemPart);
    }

    /**
     * Замыкание каждого корня по общему с JSON-схемой правилу обхода. Порядок обнаружения на
     * выход не влияет — ниже множество сортируется по короткому имени (детерминированность
     * префикса implicit-кэша).
     *
     * @param  class-string[]  $classes
     * @return class-string[]
     */
    private function expand(array $classes): array
    {
        $expanded = [];

        foreach ($classes as $class) {
            foreach ($this->types->closure($class) as $nested) {
                $expanded[] = $nested;
            }
        }

        return $expanded;
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
