<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Health;

use Spatie\LaravelData\Data;

/**
 * Измеримый исход разбора одной вкладки карточки ЕИС. Это снимок последнего разбора, а не
 * журнал: его сохраняет только `ProcurementStoreRepository::markDetailFetched()`.
 *
 * @param  list<array{selector: string, critical: bool, matches: int}>  $anchors
 */
final class CardParseOutcome extends Data
{
    public function __construct(
        public string $status,
        /** @var list<array{selector: string, critical: bool, matches: int}> */
        public array $anchors,
        public int $anchorsMissingCritical,
        public int $keyFieldsExpected,
        public int $keyFieldsFilled,
    ) {}

    /** @return array{status: string, anchors: list<array{selector: string, critical: bool, matches: int}>, anchors_missing_critical: int, key_fields_expected: int, key_fields_filled: int} */
    public function toPayload(): array
    {
        return [
            'status' => $this->status,
            'anchors' => $this->anchors,
            'anchors_missing_critical' => $this->anchorsMissingCritical,
            'key_fields_expected' => $this->keyFieldsExpected,
            'key_fields_filled' => $this->keyFieldsFilled,
        ];
    }
}
