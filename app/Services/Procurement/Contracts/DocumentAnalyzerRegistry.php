<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Enums\Procurement\AiDocumentType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Реестр «тип документа → анализатор» (§5 контракта P2.1) — ЧИСТЫЙ поиск, решений не принимает.
 *
 * Носитель регистрации — одна строка `config('ai.analyzers')` вида `тип => ::class` (D14):
 * значения ИМЕНА КЛАССОВ строками, замыканий нет, поэтому массив переживает `config:cache`.
 * Инстанс резолвит контейнер, конструкторная инъекция анализатора работает как у любого сервиса.
 *
 * Реестр собирается один раз (синглтон, первый резолв) и на сборке проверяет инварианты §5.4:
 * ключ — значение `AiDocumentType` и это НЕ `unknown`; класс реализует `DocumentAnalyzer`.
 * Проверка «шаги/назначения анализатора не пересекаются с ядерными» (§5.4 п. 2) появится вместе
 * с расщеплением enum'ов на ядерные и типовые — до него множества «ядерных» значений в коде нет
 * (P2.5, там же реализуется валидация `step`/`purpose` на границе записи, §7 контракта).
 *
 * `AiDocumentType::Unknown` зарегистрировать НЕЛЬЗЯ: «анализатор неизвестного типа» вернул бы
 * ветвление по типу в ядро через чёрный ход. Решение «неизвестный тип → `ignored`» остаётся
 * в ядре (`ScreeningOutcomeHandler`) — это факт ветвления скрининга, а не факт реестра.
 */
final readonly class DocumentAnalyzerRegistry
{
    /** @var array<string, DocumentAnalyzer> */
    private array $analyzers;

    public function __construct(Container $container)
    {
        $analyzers = [];

        /** @var array<array-key, mixed> $registrations */
        $registrations = (array) config('ai.analyzers', []);

        foreach ($registrations as $type => $class) {
            $documentType = AiDocumentType::tryFrom((string) $type);

            if ($documentType === null) {
                throw new InvalidArgumentException("config('ai.analyzers'): «{$type}» не является значением AiDocumentType.");
            }

            if ($documentType === AiDocumentType::Unknown) {
                throw new InvalidArgumentException("config('ai.analyzers'): тип «{$documentType->value}» не имеет анализатора по контракту.");
            }

            if (! is_string($class)) {
                throw new InvalidArgumentException("config('ai.analyzers'): значение для «{$documentType->value}» обязано быть именем класса строкой.");
            }

            $analyzer = $container->make($class);

            if (! $analyzer instanceof DocumentAnalyzer) {
                throw new InvalidArgumentException("config('ai.analyzers'): {$class} не реализует ".DocumentAnalyzer::class.'.');
            }

            $analyzers[$documentType->value] = $analyzer;
        }

        $this->analyzers = $analyzers;
    }

    /**
     * Анализатор типа или `null`, если типу анализатор не назначен (в том числе для
     * `AiDocumentType::Unknown`, который зарегистрировать нельзя по построению).
     */
    public function for(AiDocumentType $documentType): ?DocumentAnalyzer
    {
        return $this->analyzers[$documentType->value] ?? null;
    }
}
