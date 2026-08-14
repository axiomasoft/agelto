<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use BackedEnum;
use ReflectionClass;

/**
 * Собирает блок СЛОВАРЕЙ СИНОНИМОВ контрактов шага для системной инструкции LLM (P2.5, шаг 3
 * порядка D8). Структуру контракта ответа (типы, обязательность, вложенность, описания свойств)
 * несёт ИСХОДЯЩАЯ СХЕМА провайдера (`JsonSchemaGenerator` + `#[Description]`, P2.3/P2.4) — второй
 * носитель той же структуры (печать PHP-исходника, приём v1
 * `Old/app/Services/PromptService.php::getCleanSchema`) снят здесь: два носителя одного факта
 * дороже одного в поддержке и расходятся молча (D88).
 *
 * Схеме остаётся недоступен ровно один класс содержимого — синонимы `#[Alias]`: у
 * `EstimateHeaderMap` нет узла схемы вовсе (он не часть ответа ни одного шага), а синонимы кейсов
 * `ResourceType` и свойств `EstimateRow`/`PassportResponse` схема не переносит (в ней живут
 * значения enum'а и `description`, но не список того, как та же величина называется в документе).
 * Поэтому блок печатает ТОЛЬКО их — типизированно, по атрибутам через `ContractAttributeReader`,
 * без PHP-синтаксиса (вариант C OQ-2, D101).
 *
 * Выход обязан быть байт-в-байт стабильным между запусками (база `rules_hash` и implicit-cache
 * префикса): классы сортируются по короткому имени, свойства идут в порядке конструктора (тот же
 * единый порядок, что в схеме — D89), кейсы enum'а — в порядке объявления.
 *
 * Обход — ТРАНЗИТИВНОЕ ЗАМЫКАНИЕ переданных классов (D51, P6.1) по общему с JSON-схемой правилу
 * (`DataTypeGraph`); словари, до которых обход не достаёт, объявляет сам ТИП и передаёт их в
 * список явно (`EstimateStepDictionaries`).
 */
final readonly class PromptContextGenerator
{
    private const string HEADER = 'Словари синонимов. Слева — каноническое имя поля или значения контракта, справа — как та же величина может называться в документе. Сопоставляй по смыслу, а не по написанию; список синонимов не исчерпывающий.';

    public function __construct(
        private DataTypeGraph $types = new DataTypeGraph,
        private ContractAttributeReader $attributes = new ContractAttributeReader,
    ) {}

    /**
     * @param  class-string[]  $classes  корни блока: обёртки ответа шага и словари типа.
     */
    public function generate(array $classes): string
    {
        $blocks = [];

        foreach ($this->aliasCarriers($classes) as $class) {
            $blocks[] = $this->shortName($class).":\n".implode("\n", $this->aliasLines($class));
        }

        return $blocks === [] ? '' : self::HEADER."\n\n".implode("\n\n", $blocks);
    }

    /**
     * Классы замыкания, реально несущие `#[Alias]` — единственное содержимое печатаемого прежде
     * исходника, которого исходящая схема не переносит (§2 `research/03-prompt-contract.md`).
     *
     * @param  class-string[]  $classes
     * @return list<class-string>
     */
    private function aliasCarriers(array $classes): array
    {
        $carriers = [];

        foreach ($this->sortByShortName($this->expand($classes)) as $class) {
            if ($this->aliasLines($class) !== []) {
                $carriers[] = $class;
            }
        }

        return $carriers;
    }

    /**
     * sha1 системной части промта — фиксирует версию контракта (`llm_prompts.rules_hash`).
     */
    public function rulesHash(string $systemPart): string
    {
        return sha1($systemPart);
    }

    /**
     * Синонимы одного класса: свойства Data-контракта ИЛИ кейсы enum'а — носителем `#[Alias]`
     * бывает и то, и другое (`EstimateHeaderMap` против `ResourceType`).
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function aliasLines(string $class): array
    {
        $aliases = is_a($class, BackedEnum::class, true)
            ? $this->attributes->caseAliases($class)
            : $this->attributes->aliases($class);

        $lines = [];

        foreach ($aliases as $name => $synonyms) {
            if ($synonyms === []) {
                continue;
            }

            $lines[] = "- {$name}: ".implode(', ', array_map(static fn (string $synonym): string => "«{$synonym}»", $synonyms));
        }

        return $lines;
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

        return array_values(array_unique($expanded));
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

    /** @param class-string $class */
    private function shortName(string $class): string
    {
        return new ReflectionClass($class)->getShortName();
    }
}
