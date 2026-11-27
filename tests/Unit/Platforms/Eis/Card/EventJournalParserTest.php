<?php

declare(strict_types=1);

use App\Services\Platforms\Eis\Card\EventJournalParser;

function eventJournalFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/event-journal.html'));
}

test('parse разбирает строки журнала событий с датой и текстом (реальная вкладка event-journal)', function () {
    // Вкладка отдаёт «(МСК+9)» (Камчатка): время переводится в UTC = локальное − 12 ч.
    $entries = app(EventJournalParser::class)->parse(eventJournalFixtureHtml());

    expect($entries)->toHaveCount(5);

    expect($entries[0]->occurredAt->format('Y-m-d H:i:s'))->toBe('2025-01-22 21:29:00')
        ->and($entries[0]->rawText)->toContain('переведена на этап «Определение поставщика завершено»');

    expect($entries[2]->occurredAt->format('Y-m-d H:i:s'))->toBe('2025-01-22 21:00:00')
        ->and($entries[2]->rawText)->toContain('переведена на этап «Работа комиссии»');

    expect($entries[3]->occurredAt->format('Y-m-d H:i:s'))->toBe('2025-01-14 23:55:00')
        ->and($entries[3]->rawText)->toContain('переведена на этап «Подача заявок»');

    expect($entries[4]->rawText)->toContain('Размещен документ');
});

test('parse возвращает пустой список при отсутствии таблицы #event', function () {
    $html = preg_replace('#<div id="event-journal-wrapper">.*?</div>#s', '', eventJournalFixtureHtml());

    $entries = app(EventJournalParser::class)->parse($html);

    expect($entries)->toBe([]);
});

test('parse пропускает строку с нераспознаваемой датой без падения', function () {
    // «23.01.2025 09:00» встречается в фикстуре ровно один раз (третья строка) — точечная порча.
    $html = str_replace('23.01.2025 09:00', 'не дата', eventJournalFixtureHtml());

    $entries = app(EventJournalParser::class)->parse($html);

    expect($entries)->toHaveCount(4);
});
