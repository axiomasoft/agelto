<?php

declare(strict_types=1);

use App\Services\Procurement\Eis\Card\EventJournalParser;

function eventJournalFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/event-journal.html'));
}

test('parse разбирает строки журнала событий с датой и текстом', function () {
    $entries = app(EventJournalParser::class)->parse(eventJournalFixtureHtml());

    expect($entries)->toHaveCount(3);

    expect($entries[0]->occurredAt->format('Y-m-d H:i:s'))->toBe('2026-06-30 08:22:00')
        ->and($entries[0]->rawText)->toContain('Размещен документ');

    expect($entries[1]->rawText)->toContain('переведена на этап «Подача заявок»');
    expect($entries[2]->rawText)->toContain('переведена на этап «Работа комиссии»');
});

test('parse возвращает пустой список при отсутствии блока event-journal', function () {
    $html = preg_replace('#<div class="event-journal">.*?</div>#s', '', eventJournalFixtureHtml());

    $entries = app(EventJournalParser::class)->parse($html);

    expect($entries)->toBe([]);
});

test('parse пропускает строку с нераспознаваемой датой без падения', function () {
    $html = str_replace('11.07.2026 07:00 (МСК+3)', 'не дата', eventJournalFixtureHtml());

    $entries = app(EventJournalParser::class)->parse($html);

    expect($entries)->toHaveCount(2);
});
