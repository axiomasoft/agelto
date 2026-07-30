<?php

declare(strict_types=1);

use App\Dto\Procurement\Analyzers\Estimate\DiscoveryMarkerLine;
use App\Enums\Procurement\DiscoveryMarker;
use App\Services\Procurement\Analyzers\Estimate\SegmentAssembler;

// Юнит-тесты SegmentAssembler (Scope Included P6.4, сценарии (а)-(г) из phases/P6.md): чистая
// детерминированная функция БЕЗ LLM, вход — плоский список маркеров В ПОРЯДКЕ ПРИХОДА ОКОН.

function marker(int $page, DiscoveryMarker $marker, ?string $title = null): DiscoveryMarkerLine
{
    return new DiscoveryMarkerLine(page: $page, marker: $marker, title: $title);
}

test('граница ровно на overlap-странице — 2 верных сегмента без потери страницы', function (): void {
    // Окно 0 (страницы 1-3): смета №1 заканчивается на стр. 3 (стык). Окно 1 (страницы 3-5,
    // overlap на стр. 3): смета №2 начинается на стр. 4 (не на overlap-странице — однозначно).
    $markers = [
        marker(1, DiscoveryMarker::EstimateStart, 'Смета №1'),
        marker(3, DiscoveryMarker::EstimateEnd),
        marker(4, DiscoveryMarker::EstimateStart, 'Смета №2'),
        marker(6, DiscoveryMarker::EstimateEnd),
    ];

    $segments = (new SegmentAssembler)->assemble($markers, totalPages: 6);

    expect($segments)->toHaveCount(2)
        ->and($segments[0])->toBe(['ordinal' => 1, 'page_start' => 1, 'page_end' => 3, 'title' => 'Смета №1', 'suspicious' => false])
        ->and($segments[1])->toBe(['ordinal' => 2, 'page_start' => 4, 'page_end' => 6, 'title' => 'Смета №2', 'suspicious' => false]);
});

test('противоречивые маркеры на overlap-странице — раннее окно побеждает для start, позднее для end', function (): void {
    // Окно 0 (1-3) видит только continuation на стр.3 (не дошло до конца сметы). Окно 1 (3-5)
    // видит estimate_end на стр.3 (там смета реально заканчивается) — позднее окно побеждает для
    // end (§4.2 правило), сохраняя единственный маркер estimate_start с окна 0 на стр.1.
    $markers = [
        marker(1, DiscoveryMarker::EstimateStart, 'Смета №1'),
        marker(2, DiscoveryMarker::Continuation),
        marker(3, DiscoveryMarker::Continuation),
        marker(3, DiscoveryMarker::EstimateEnd),
        marker(4, DiscoveryMarker::EstimateStart, 'Смета №2'),
    ];

    $segments = (new SegmentAssembler)->assemble($markers, totalPages: 4);

    expect($segments)->toHaveCount(2)
        ->and($segments[0])->toBe(['ordinal' => 1, 'page_start' => 1, 'page_end' => 3, 'title' => 'Смета №1', 'suspicious' => false])
        ->and($segments[1]['page_start'])->toBe(4)
        ->and($segments[1]['suspicious'])->toBeTrue(); // не дождался estimate_end до конца документа
});

test('смета, продолжающаяся через несколько discovery-окон (continuation) — 1 сегмент, не N', function (): void {
    $markers = [
        marker(1, DiscoveryMarker::EstimateStart, 'Большая смета'),
        marker(2, DiscoveryMarker::Continuation),
        marker(3, DiscoveryMarker::Continuation),
        marker(3, DiscoveryMarker::Continuation),
        marker(4, DiscoveryMarker::Continuation),
        marker(5, DiscoveryMarker::Continuation),
        marker(6, DiscoveryMarker::Continuation),
        marker(7, DiscoveryMarker::Continuation),
        marker(8, DiscoveryMarker::EstimateEnd),
    ];

    $segments = (new SegmentAssembler)->assemble($markers, totalPages: 8);

    expect($segments)->toHaveCount(1)
        ->and($segments[0])->toBe(['ordinal' => 1, 'page_start' => 1, 'page_end' => 8, 'title' => 'Большая смета', 'suspicious' => false]);
});

test('estimate_end без предшествующего estimate_start — сегмент помечен suspicious, страница не теряется', function (): void {
    $markers = [
        marker(2, DiscoveryMarker::EstimateEnd),
        marker(3, DiscoveryMarker::EstimateStart, 'Смета №2'),
        marker(4, DiscoveryMarker::EstimateEnd),
    ];

    $segments = (new SegmentAssembler)->assemble($markers, totalPages: 4);

    expect($segments)->toHaveCount(2)
        // Страница 1 (нет маркеров вовсе) открывает suspicious-сегмент; estimate_end на стр.2
        // застаёт его уже открытым — закрывает СЮДА (не отдельным однострочным сегментом), но
        // suspicious-флаг ambiguous-открытия сохраняется (не затирается unconditional false).
        ->and($segments[0])->toBe(['ordinal' => 1, 'page_start' => 1, 'page_end' => 2, 'title' => null, 'suspicious' => true])
        // Смета №2 корректно открыта явным estimate_start и закрыта явным estimate_end — не suspicious.
        ->and($segments[1])->toBe(['ordinal' => 2, 'page_start' => 3, 'page_end' => 4, 'title' => 'Смета №2', 'suspicious' => false]);
});

test('estimate_start без последующего estimate_end (конец документа) — сегмент suspicious, страницы не теряются', function (): void {
    $markers = [
        marker(1, DiscoveryMarker::EstimateStart, 'Смета №1'),
        marker(2, DiscoveryMarker::EstimateEnd),
        marker(3, DiscoveryMarker::EstimateStart, 'Смета №2'),
    ];

    $segments = (new SegmentAssembler)->assemble($markers, totalPages: 5);

    expect($segments)->toHaveCount(2)
        ->and($segments[0])->toBe(['ordinal' => 1, 'page_start' => 1, 'page_end' => 2, 'title' => 'Смета №1', 'suspicious' => false])
        // Не дождалась estimate_end до конца документа (стр.5) — закрыта принудительно, suspicious.
        ->and($segments[1])->toBe(['ordinal' => 2, 'page_start' => 3, 'page_end' => 5, 'title' => 'Смета №2', 'suspicious' => true]);
});

test('сегментация детерминирована — повторный вызов с тем же набором маркеров даёт тот же результат', function (): void {
    $markers = [
        marker(1, DiscoveryMarker::EstimateStart, 'Смета №1'),
        marker(2, DiscoveryMarker::Continuation),
        marker(3, DiscoveryMarker::EstimateEnd),
        marker(4, DiscoveryMarker::EstimateStart, 'Смета №2'),
        marker(5, DiscoveryMarker::EstimateEnd),
    ];

    $assembler = new SegmentAssembler;

    expect($assembler->assemble($markers, totalPages: 5))
        ->toBe($assembler->assemble($markers, totalPages: 5));
});
