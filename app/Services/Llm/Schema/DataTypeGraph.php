<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;

/**
 * ЕДИНСТВЕННЫЙ владелец правила «какие типы вложены в Data-контракт»: обход конструктора,
 * элементы массивов по докблоку `@param X[] $prop`, enum'ы, самоссылки без зацикливания.
 * Правило читают ОБА носителя контракта шага — блок схем системной инструкции
 * (`PromptContextGenerator`) и JSON-схема structured output (`JsonSchemaGenerator`).
 *
 * Вторая реализация рядом запрещена: расхождение носителей глазами не видно — класс попадает в
 * схему, но не в контекст, и модель получает имена полей без единого пояснения (находка F4
 * аудита согласованности схемы и промпта). Инвариант держит тест когерентности «множество классов
 * блока схем шага ⊇ множество классов JSON-схемы того же шага».
 *
 * Граф отвечает на вопрос «что вложено», а не «в каком порядке это печатать»: порядок —
 * инвариант потребителя (детерминированный префикс implicit-кэша собирает
 * `PromptContextGenerator`), поэтому замыкание возвращается в порядке обнаружения.
 */
final readonly class DataTypeGraph
{
    /**
     * Транзитивное замыкание вложенных Data-классов и enum'ов, включая сам корень. Корень, не
     * являющийся Data-классом (enum-словарь), возвращается один — вложений у него нет.
     *
     * @param  class-string  $class
     * @return list<class-string>
     */
    public function closure(string $class): array
    {
        $closure = [];
        $queue = [$class];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (in_array($current, $closure, true)) {
                continue;
            }

            $closure[] = $current;

            foreach ($this->nestedTypes($current) as $nested) {
                $queue[] = $nested;
            }
        }

        return $closure;
    }

    /**
     * Прямые вложения одного Data-класса: типы свойств конструктора, доехавшие до Data-класса или
     * enum'а. Скаляры и типы, которых правило не знает, вложением не считаются — расширять этот
     * ответ значит менять контракт обоих носителей сразу.
     *
     * @param  class-string  $class
     * @return list<class-string>
     */
    public function nestedTypes(string $class): array
    {
        if (! $this->isDataClass($class)) {
            return [];
        }

        $constructor = new ReflectionClass($class)->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $nested = [];

        foreach ($constructor->getParameters() as $parameter) {
            $typeName = $this->parameterTypeName($parameter, $constructor);

            if ($typeName === null) {
                continue;
            }

            if ($this->isDataClass($typeName) || $this->isEnum($typeName)) {
                $nested[] = $typeName;
            }
        }

        return $nested;
    }

    /**
     * Тип элементов массива по докблоку конструктора (`@param X[] $prop`) — нативной типизации
     * элементов в PHP нет, и это ЕДИНСТВЕННОЕ место, где правило разбора живёт.
     */
    public function docblockArrayItemType(ReflectionParameter $parameter, ReflectionMethod $constructor): ?string
    {
        $doc = $constructor->getDocComment();

        if ($doc === false) {
            return null;
        }

        $paramName = $parameter->getName();

        if (preg_match('/@param\s+(?:([^\s\[]+)\[\]|list<([^>]+)>)\s+\$'.preg_quote($paramName, '/').'\b/', $doc, $matches) !== 1) {
            return null;
        }

        $itemType = $matches[1] !== '' ? $matches[1] : $matches[2];

        return $this->resolveClassName($itemType, $constructor->getDeclaringClass());
    }

    /**
     * Сужение типа объявлено предикатом: вынос правила из `JsonSchemaGenerator` не имеет права
     * забрать у потребителя знание, которое ему давал прямой `is_subclass_of`.
     *
     * @phpstan-assert-if-true class-string<Data> $typeName
     */
    public function isDataClass(string $typeName): bool
    {
        return is_subclass_of($typeName, Data::class);
    }

    /**
     * @phpstan-assert-if-true class-string<\UnitEnum> $typeName
     */
    public function isEnum(string $typeName): bool
    {
        return enum_exists($typeName);
    }

    /**
     * Имя типа свойства для обхода: для массива — тип элементов из докблока, иначе — сам тип.
     * Union/intersection обходу не поддаются и вложением не считаются (JSON-схема на них падает
     * явной ошибкой — там это отказ, здесь просто нечего обходить).
     */
    private function parameterTypeName(ReflectionParameter $parameter, ReflectionMethod $constructor): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        return $type->getName() === 'array'
            ? $this->docblockArrayItemType($parameter, $constructor)
            : $type->getName();
    }

    /**
     * @param  ReflectionClass<object>  $declaringClass
     */
    private function resolveClassName(string $shortType, ReflectionClass $declaringClass): string
    {
        if (in_array($shortType, ['string', 'int', 'float', 'bool'], true)) {
            return $shortType;
        }

        $candidate = $declaringClass->getNamespaceName().'\\'.$shortType;

        return class_exists($candidate) || enum_exists($candidate) ? $candidate : $shortType;
    }
}
