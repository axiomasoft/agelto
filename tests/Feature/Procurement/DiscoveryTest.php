<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Models\Platform\Registry;
use App\Models\Platform\StatusMapping;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Services\Procurement\ProcurementIngestProcessor;
use Illuminate\Support\Carbon;
use Tests\Support\Procurement\SnapshotFactory;

/**
 * §11 «Новая закупка найдена (листинг)» и «Редакция без изменений ядра» — через
 * ProcurementIngestProcessor, без прямых insert'ов в обход процессора.
 *
 * Статус источника заранее замаплен на канон (сценарий «неизвестный статус» — отдельно
 * в StatusMappingTest), иначе первый ingest для нового registry всегда завёл бы ещё и
 * побочное событие status_changed из-за авто-заведения unknown-маппинга.
 */
test('новая закупка находит insert prc_procurements + первую ревизию + событие discovered', function () {
    $registry = Registry::factory()->create();
    StatusMapping::factory()->create([
        'registry_id' => $registry->id,
        'source_status' => 'Размещение извещения',
        'canonical_status' => 'announced',
    ]);
    $data = SnapshotFactory::make($registry);

    $procurement = app(ProcurementIngestProcessor::class)->ingest($data);

    expect($procurement)->toBeInstanceOf(Procurement::class)
        ->and($procurement->registry_id)->toBe($registry->id)
        ->and($procurement->source_number)->toBe('0173100000123000001')
        ->and($procurement->revision_count)->toBe(1);

    $revision = ProcurementRevision::query()->where('procurement_id', $procurement->id)->sole();
    expect($revision->revision_number)->toBe(1);

    $event = ProcurementEvent::query()->where('procurement_id', $procurement->id)->sole();
    expect($event->event_type)->toBe(ProcurementEventType::Discovered)
        ->and($event->revision_id)->toBe($revision->id);
});

test('редакция без изменений ядра трогает только last_seen_at, без новой ревизии', function () {
    $registry = Registry::factory()->create();
    StatusMapping::factory()->create([
        'registry_id' => $registry->id,
        'source_status' => 'Размещение извещения',
        'canonical_status' => 'announced',
    ]);
    $data = SnapshotFactory::make($registry);

    $processor = app(ProcurementIngestProcessor::class);
    $first = $processor->ingest($data);

    $secondSeenAt = Carbon::now()->addHour();
    Carbon::setTestNow($secondSeenAt);

    $second = $processor->ingest($data);

    Carbon::setTestNow();

    expect($second->id)->toBe($first->id)
        ->and($second->revision_count)->toBe(1)
        ->and($second->last_seen_at->timestamp)->toBe($secondSeenAt->timestamp);

    expect(ProcurementRevision::query()->where('procurement_id', $first->id)->count())->toBe(1);
    expect(ProcurementEvent::query()->where('procurement_id', $first->id)->count())->toBe(1);
});
