<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Crawler\Eis\Card\EventJournalParser;
use App\Services\Procurement\Detail\EventJournalProcessor;
use Illuminate\Support\Facades\Log;

function eventJournalEntriesForProcessorTest(): array
{
    // `prc_events` партиционирована по месяцам вокруг реального времени прогона (см.
    // `2026_07_04_100013_create_prc_events_table.php`); дата «30.06.2026» из фикстуры
    // (унаследована от P3.1) может выпасть из окна премейка партиций — сдвигаем на
    // текущий месяц теста, сохраняя оригинальный HTML-файл фикстуры нетронутым.
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/event-journal.html'));
    $html = str_replace('30.06.2026 08:22', now()->startOfMonth()->addDay()->format('d.m.Y H:i'), $html);
    $html = str_replace('11.07.2026 07:00', now()->startOfMonth()->addDays(2)->format('d.m.Y H:i'), $html);

    return app(EventJournalParser::class)->parse($html);
}

test('process создаёт prc_events из распознанных строк журнала и пропускает нераспознанные', function () {
    // 1 предупреждение на нераспознанную строку + 1 итоговое с числом пропусков.
    Log::shouldReceive('warning')->twice();

    $procurement = Procurement::factory()->create();

    app(EventJournalProcessor::class)->process($procurement, eventJournalEntriesForProcessorTest());

    // 3 строки в фикстуре: 1 нераспознанная («Размещен документ…»), 2 — переход этапа.
    expect(ProcurementEvent::query()->where('procurement_id', $procurement->id)->count())->toBe(2);

    $events = ProcurementEvent::query()->where('procurement_id', $procurement->id)->orderBy('occurred_at')->get();

    expect($events[0]->event_type)->toBe(ProcurementEventType::StatusChanged)
        ->and($events[0]->new_value)->toBe('Подача заявок')
        ->and($events[1]->new_value)->toBe('Работа комиссии');
});

test('повторный process идемпотентен — события не задваиваются', function () {
    // 2 предупреждения на прогон (нераспознанная строка + итог) × 2 прогона.
    Log::shouldReceive('warning')->times(4);

    $procurement = Procurement::factory()->create();
    $entries = eventJournalEntriesForProcessorTest();

    app(EventJournalProcessor::class)->process($procurement, $entries);
    app(EventJournalProcessor::class)->process($procurement, $entries);

    expect(ProcurementEvent::query()->where('procurement_id', $procurement->id)->count())->toBe(2);
});
