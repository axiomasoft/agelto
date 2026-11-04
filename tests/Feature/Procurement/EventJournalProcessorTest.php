<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Platforms\Eis\Card\EventJournalParser;
use App\Services\Procurement\Detail\EventJournalProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function eventJournalEntriesForProcessorTest(): array
{
    // `prc_events` партиционирована по месяцам вокруг реального времени прогона (см.
    // `2026_07_04_100013_create_prc_events_table.php`); даты реальной фикстуры (живая
    // проба «23.01.2025»/«15.01.2025») выпадают из окна премейка партиций — сдвигаем
    // на текущий месяц теста, сохраняя оригинальный HTML-файл фикстуры нетронутым и порядок
    // строк (относительные сдвиги дней сохраняют хронологию).
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/event-journal.html'));
    $html = str_replace('23.01.2025 09:29', now()->startOfMonth()->addDay()->format('d.m.Y').' 09:29', $html);
    $html = str_replace('23.01.2025 09:00', now()->startOfMonth()->addDays(2)->format('d.m.Y').' 09:00', $html);
    $html = str_replace('15.01.2025 11:55', now()->startOfMonth()->addDays(3)->format('d.m.Y').' 11:55', $html);

    return app(EventJournalParser::class)->parse($html);
}

test('process создаёт prc_events из распознанных строк журнала и пропускает нераспознанные', function () {
    // 2 предупреждения на нераспознанные строки + 1 итоговое с числом пропусков.
    Log::shouldReceive('warning')->times(3);

    $procurement = Procurement::factory()->create();

    app(EventJournalProcessor::class)->process($procurement, eventJournalEntriesForProcessorTest());

    // 5 строк в фикстуре: 2 нераспознанные («Размещен «Протокол…»», «Размещен документ…»),
    // 3 — переход этапа.
    expect(ProcurementEvent::query()->where('procurement_id', $procurement->id)->count())->toBe(3);

    $events = ProcurementEvent::query()->where('procurement_id', $procurement->id)->orderBy('occurred_at')->get();

    expect($events[0]->event_type)->toBe(ProcurementEventType::StatusChanged)
        ->and($events[0]->new_value)->toBe('Определение поставщика завершено')
        ->and($events[1]->new_value)->toBe('Работа комиссии')
        ->and($events[2]->new_value)->toBe('Подача заявок');
});

test('повторный process идемпотентен — события не задваиваются', function () {
    // 3 предупреждения на прогон (2 нераспознанные строки + итог) × 2 прогона.
    Log::shouldReceive('warning')->times(6);

    $procurement = Procurement::factory()->create();
    $entries = eventJournalEntriesForProcessorTest();

    app(EventJournalProcessor::class)->process($procurement, $entries);
    app(EventJournalProcessor::class)->process($procurement, $entries);

    expect(ProcurementEvent::query()->where('procurement_id', $procurement->id)->count())->toBe(3);
});

test('январская карточка сохраняет декабрьское событие без переноса даты и повторных записей', function () {
    // Historical imports require partition preflight; this fixture explicitly supplies
    // that prerequisite in the isolated agelto_test transaction, without retention.
    $table = (new ProcurementEvent)->getTable();
    DB::statement("CREATE TABLE IF NOT EXISTS {$table}_2024_12 PARTITION OF {$table} FOR VALUES FROM ('2024-12-01 00:00:00+00') TO ('2025-01-01 00:00:00+00')");
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/event-journal-december-2024.html'));
    $entries = app(EventJournalParser::class)->parse($html);
    $procurement = Procurement::factory()->create();
    $processor = app(EventJournalProcessor::class);
    $processor->process($procurement, $entries);
    $ids = ProcurementEvent::query()->where('procurement_id', $procurement->id)->orderBy('id')->pluck('id')->all();
    $processor->process($procurement, $entries);

    expect($ids)->not->toBeEmpty()
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)->whereDate('occurred_at', '2024-12-29')->exists())->toBeTrue()
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)->orderBy('id')->pluck('id')->all())->toBe($ids);
});
