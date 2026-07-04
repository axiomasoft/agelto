<?php

declare(strict_types=1);

use App\Models\Procurement\Cancellation;
use App\Models\Procurement\Clarification;
use App\Models\Procurement\Procurement;
use Illuminate\Support\Carbon;

/**
 * §11 «Разъяснение / отмена» — prc_clarifications/prc_cancellations, отмена отмены → reverted_at.
 */
test('разъяснение документации фиксируется отдельной строкой без сдвига ревизии закупки', function () {
    $procurement = Procurement::factory()->create(['revision_count' => 1]);

    $clarification = Clarification::factory()->create([
        'procurement_id' => $procurement->id,
        'question' => 'Уточните сроки поставки',
        'answer' => null,
    ]);

    expect($clarification->procurement_id)->toBe($procurement->id)
        ->and($clarification->answer)->toBeNull();

    $procurement->refresh();
    expect($procurement->revision_count)->toBe(1);
});

test('отмена процедуры и последующая отмена отмены фиксируются через reverted_at', function () {
    $procurement = Procurement::factory()->create();

    $cancellation = Cancellation::factory()->create([
        'procurement_id' => $procurement->id,
        'decision_at' => Carbon::parse('2026-02-01 10:00:00'),
        'reason' => 'Отсутствие заявок',
        'reverted_at' => null,
    ]);

    expect($cancellation->reverted_at)->toBeNull();

    $revertedAt = Carbon::parse('2026-02-05 10:00:00');
    $cancellation->update(['reverted_at' => $revertedAt]);

    expect($cancellation->fresh()->reverted_at->equalTo($revertedAt))->toBeTrue();
});
