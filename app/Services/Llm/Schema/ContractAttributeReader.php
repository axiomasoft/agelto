<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use App\Attributes\Ai\Alias;
use App\Attributes\Ai\Description;
use BackedEnum;
use ReflectionClass;
use ReflectionEnum;

/**
 * Ридер атрибутов `#[Description]`/`#[Alias]` контракта — узкий, только рефлексия по
 * ≤6 классам на вызов, без кэша (`research/03-prompt-contract.md` §5.2). Не знает ни про
 * JSON Schema, ни про промт: решение «эмитить ли `description`»/«печатать ли словарь» принимает
 * потребитель. Докблоки не парсит — ни как фолбэк, ни временно.
 */
final readonly class ContractAttributeReader
{
    /**
     * @param  class-string  $class
     * @return array<string, string> имя свойства → текст описания; свойство без атрибута — БЕЗ ключа
     */
    public function descriptions(string $class): array
    {
        $descriptions = [];

        foreach ($this->constructorParameters($class) as $parameter) {
            $attributes = $parameter->getAttributes(Description::class);

            if ($attributes === []) {
                continue;
            }

            $descriptions[$parameter->getName()] = $attributes[0]->newInstance()->text;
        }

        return $descriptions;
    }

    /**
     * `#[Alias]` не допускает `TARGET_PARAMETER` (симметрия с уже существующим использованием на
     * `EstimateHeaderMap`/`ResourceType`) — читается через `ReflectionProperty`, не
     * `ReflectionParameter`: `newInstance` проверяет цель ПО РЕФЛЕКТОРУ, которым атрибут снят.
     *
     * @param  class-string  $class
     * @return array<string, list<string>> имя свойства → синонимы; свойство без атрибута — БЕЗ ключа
     */
    public function aliases(string $class): array
    {
        $aliases = [];
        $reflection = new ReflectionClass($class);

        foreach ($this->constructorParameters($class) as $parameter) {
            $attributes = $reflection->getProperty($parameter->getName())->getAttributes(Alias::class);

            if ($attributes === []) {
                continue;
            }

            $aliases[$parameter->getName()] = array_values($attributes[0]->newInstance()->aliases);
        }

        return $aliases;
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, list<string>> value кейса → синонимы; кейс без атрибута — БЕЗ ключа
     */
    public function caseAliases(string $enum): array
    {
        $aliases = [];

        foreach (new ReflectionEnum($enum)->getCases() as $case) {
            $attributes = $case->getAttributes(Alias::class);

            if ($attributes === []) {
                continue;
            }

            $aliases[(string) $case->getBackingValue()] = array_values($attributes[0]->newInstance()->aliases);
        }

        return $aliases;
    }

    /**
     * @param  class-string  $class
     * @return list<\ReflectionParameter>
     */
    private function constructorParameters(string $class): array
    {
        $constructor = new ReflectionClass($class)->getConstructor();

        return $constructor === null ? [] : $constructor->getParameters();
    }
}
