<?php

declare(strict_types=1);

use App\Services\Procurement\Health\CardAnchorProbe;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

test('зонд считает совпадения якорей и заполненные ключевые поля', function (): void {
    $outcome = app(CardAnchorProbe::class)->probe(
        html: CardHtmlFixture::commonInfo(),
        tabDescription: [
            'status' => 'measured',
            'anchors' => [
                ['selector' => 'div#positionKTRU', 'critical' => true, 'min_matches' => 1],
                ['selector' => 'span.section__title', 'critical' => true, 'min_matches' => 1],
            ],
            'key_fields' => ['ikz_year', 'contact_person_email'],
        ],
        keyFieldValues: ['ikz_year' => 2025, 'contact_person_email' => 'contact@example.test'],
    );

    expect($outcome->status)->toBe('measured')
        ->and($outcome->anchors[0]['matches'])->toBe(1)
        ->and($outcome->anchors[1]['matches'])->toBeGreaterThan(0)
        ->and($outcome->anchorsMissingCritical)->toBe(0)
        ->and($outcome->keyFieldsExpected)->toBe(2)
        ->and($outcome->keyFieldsFilled)->toBe(2);
});

test('удалённый критический якорь фиксируется в исходе', function (): void {
    $html = str_replace('<div class="container" id="positionKTRU">', '<div class="container">', CardHtmlFixture::commonInfo());

    $outcome = app(CardAnchorProbe::class)->probe(
        html: $html,
        tabDescription: [
            'status' => 'measured',
            'anchors' => [['selector' => 'div#positionKTRU', 'critical' => true, 'min_matches' => 1]],
            'key_fields' => [],
        ],
    );

    expect($outcome->status)->toBe('measured')
        ->and($outcome->anchors[0]['matches'])->toBe(0)
        ->and($outcome->anchorsMissingCritical)->toBe(1);
});

test('зонд отличает существующие якоря от пустого результата парсера', function (): void {
    $outcome = app(CardAnchorProbe::class)->probe(
        html: CardHtmlFixture::commonInfo(),
        tabDescription: [
            'status' => 'measured',
            'anchors' => [['selector' => 'span.section__title', 'critical' => true, 'min_matches' => 1]],
            'key_fields' => ['ikz_year'],
        ],
        keyFieldValues: ['ikz_year' => null],
    );

    expect($outcome->anchorsMissingCritical)->toBe(0)
        ->and($outcome->keyFieldsExpected)->toBe(1)
        ->and($outcome->keyFieldsFilled)->toBe(0);
});

test('неснятая вкладка получает честный статус', function (): void {
    $outcome = app(CardAnchorProbe::class)->probe(
        html: null,
        tabDescription: ['anchors' => [], 'key_fields' => ['documents']],
        keyFieldValues: ['documents' => []],
    );

    expect($outcome->status)->toBe('tab_absent')
        ->and($outcome->keyFieldsExpected)->toBe(1)
        ->and($outcome->keyFieldsFilled)->toBe(0);
});

test('известно сломанная вкладка остаётся отдельным статусом', function (): void {
    $outcome = app(CardAnchorProbe::class)->probe(
        html: CardHtmlFixture::supplierResults(),
        tabDescription: [
            'status' => 'known_broken',
            'anchors' => [],
            'key_fields' => [],
        ],
    );

    expect($outcome->status)->toBe('known_broken');
});

test('ошибка селектора не роняет зонд', function (): void {
    $outcome = app(CardAnchorProbe::class)->probe(
        html: CardHtmlFixture::commonInfo(),
        tabDescription: [
            'status' => 'measured',
            'anchors' => [['selector' => '[', 'critical' => true, 'min_matches' => 1]],
            'key_fields' => [],
        ],
    );

    expect($outcome->status)->toBe('probe_failed');
});
