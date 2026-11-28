<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;

test('положительный терминальный исход определён для всех канонических статусов', function (ProcurementStatus $status, bool $expected): void {
    expect($status->isPositiveTerminal())->toBe($expected);
})->with([
    'подача заявок' => [ProcurementStatus::Bidding, false],
    'работа комиссии' => [ProcurementStatus::Evaluation, false],
    'победитель определён' => [ProcurementStatus::Awarded, true],
    'закупка завершена' => [ProcurementStatus::Completed, true],
    'закупка отменена' => [ProcurementStatus::Cancelled, false],
    'закупка не состоялась' => [ProcurementStatus::Unsuccessful, false],
    'неизвестный статус' => [ProcurementStatus::Unknown, false],
]);
