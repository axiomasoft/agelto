<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\DetailRefetchSelector;

test('due отбирает закупки, ни разу не снятые', function () {
    $procurement = Procurement::factory()->create(['detail_fetched_at' => null]);

    expect(app(DetailRefetchSelector::class)->due()->pluck('id')->all())->toContain($procurement->id);
});

test('due отбирает закупки с новой ревизией после последнего снятия', function () {
    $procurement = Procurement::factory()->create([
        'detail_fetched_at' => now()->subDay(),
        'current_revision_detected_at' => now(),
    ]);

    expect(app(DetailRefetchSelector::class)->due()->pluck('id')->all())->toContain($procurement->id);
});

test('due отбирает закупки с наступившим detail_next_retry_at', function () {
    $procurement = Procurement::factory()->create([
        'detail_fetched_at' => now()->subDay(),
        'current_revision_detected_at' => now()->subDay(),
        'detail_next_retry_at' => now()->subMinute(),
    ]);

    expect(app(DetailRefetchSelector::class)->due()->pluck('id')->all())->toContain($procurement->id);
});

test('due отбирает завершающиеся закупки на периодический ре-скан', function () {
    $procurement = Procurement::factory()->create([
        'status' => ProcurementStatus::Awarded,
        'detail_fetched_at' => now()->subMinutes((int) config('crawler.detail_rescan_interval_minutes', 60) + 1),
        'current_revision_detected_at' => now()->subMinutes((int) config('crawler.detail_rescan_interval_minutes', 60) + 1),
    ]);

    expect(app(DetailRefetchSelector::class)->due()->pluck('id')->all())->toContain($procurement->id);
});

test('due не отбирает свежеснятые закупки без триггеров', function () {
    $procurement = Procurement::factory()->create([
        'status' => ProcurementStatus::Bidding,
        'detail_fetched_at' => now(),
        'current_revision_detected_at' => now()->subDay(),
    ]);

    expect(app(DetailRefetchSelector::class)->due()->pluck('id')->all())->not->toContain($procurement->id);
});

test('due не отбирает закупки с исчерпанными ретраями (detail_failed)', function () {
    $procurement = Procurement::factory()->create([
        'detail_fetched_at' => null,
        'detail_failed' => true,
    ]);

    expect(app(DetailRefetchSelector::class)->due()->pluck('id')->all())->not->toContain($procurement->id);
});
