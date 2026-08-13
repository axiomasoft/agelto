<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

use Filament\Support\Contracts\HasLabel;

/**
 * Канонический статус закупки (закрытый codelist, по мотивам OCDS tenderStatus).
 *
 * Матрица достижимости (DOMLANG P2, источник — findings/P2-eis-stages.md §5):
 *
 * | Кейс           | Продюсер                                           | Достижим          |
 * |:---------------|:---------------------------------------------------|:------------------|
 * | Bidding        | этап ЕИС «Подача заявок»                            | да                |
 * | Evaluation     | этап ЕИС «Работа комиссии»                          | да                |
 * | Completed      | этап ЕИС «Закупка завершена»                        | да                |
 * | Cancelled      | этап ЕИС «Закупка отменена»                         | да                |
 * | Unknown        | StatusMappingService::resolve() на незнакомой паре | да                |
 * | Awarded        | P6.2: detail-слой / протоколы (prc_protocols)      | forward-provision |
 * | Unsuccessful   | detail-слой / результаты определения поставщика    | forward-provision |
 *
 * forward-provision — кейс с названным будущим продюсером и таблицей-носителем, ещё не
 * подключённым парсером (критерий D5/D13). Кейс «до подачи заявок» удалён (DOMLANG D16): ЕИС не
 * выделяет такой этап — публикация извещения сразу даёт «Подача заявок», продюсера нет и не будет.
 */
enum ProcurementStatus: string implements HasLabel
{
    case Bidding = 'bidding';
    case Evaluation = 'evaluation';
    case Awarded = 'awarded';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Unsuccessful = 'unsuccessful';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bidding => 'Подача заявок',
            self::Evaluation => 'Работа комиссии',
            self::Awarded => 'Поставщик определён',
            self::Completed => 'Завершена',
            self::Cancelled => 'Отменена',
            self::Unsuccessful => 'Не состоялась',
            self::Unknown => 'Не определён',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Unsuccessful => true,
            default => false,
        };
    }

    public function isFailed(): bool
    {
        return match ($this) {
            self::Cancelled, self::Unsuccessful => true,
            default => false,
        };
    }

    public function isPositiveTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Awarded => true,
            default => false,
        };
    }
}
