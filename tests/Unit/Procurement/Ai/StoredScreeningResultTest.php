<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;

test('читает typed screening v2 envelope', function (): void {
    $result = StoredScreeningResult::fromArray([
        'schema_version' => 2,
        'screening' => [
            'confidence' => 0.91,
            'summary' => 'Смета на ремонт кровли',
            'estimate_cardinality' => 'single',
            'tags' => ['Москва', 'Кровля'],
            'classification_note' => null,
            'page_count_estimate' => 12,
        ],
        'decision' => [
            'disposition' => 'extract',
            'reason' => 'analyzer_available',
            'extraction_dispatched' => true,
        ],
    ]);

    expect($result->estimateCardinality)->toBe(EstimateCardinality::Single)
        ->and($result->decision->disposition)->toBe(ScreeningDisposition::Extract)
        ->and($result->decision->reason)->toBe(ScreeningDecisionReason::AnalyzerAvailable)
        ->and($result->toArray()['screening']['summary'])->toBe('Смета на ремонт кровли');
});

test('детерминированно переносит flat unknown result и восстанавливает его', function (): void {
    $legacy = [
        'confidence' => 0.9,
        'description' => "  Прочий\nдокумент  ",
        'tags' => ['Москва'],
        'page_count_estimate' => null,
        'ignore_reason' => null,
        'branch_reason' => 'unknown',
        'extraction_dispatched' => false,
    ];

    $result = StoredScreeningResult::fromLegacy(documentType: 'unknown', value: $legacy);

    expect($result->estimateCardinality)->toBe(EstimateCardinality::Unknown)
        ->and($result->decision->disposition)->toBe(ScreeningDisposition::Ignore)
        ->and($result->decision->reason)->toBe(ScreeningDecisionReason::UnknownType)
        ->and($result->summary)->toBe('Прочий документ')
        ->and($result->toLegacyArray())->toEqual([...$legacy, 'description' => 'Прочий документ']);
});

test('отклоняет legacy result без точного decision carrier', function (): void {
    expect(fn (): StoredScreeningResult => StoredScreeningResult::fromLegacy(documentType: 'estimate', value: [
        'confidence' => 0.95,
        'description' => 'Смета',
        'tags' => [],
        'page_count_estimate' => 1,
        'ignore_reason' => null,
        'extraction_dispatched' => false,
    ]))->toThrow(InvalidArgumentException::class, 'no deterministic decision mapping');
});

test('отклоняет invalid cardinality envelope', function (): void {
    expect(fn (): StoredScreeningResult => StoredScreeningResult::fromArray([
        'schema_version' => 2,
        'screening' => [
            'confidence' => 0.9,
            'summary' => 'Документ',
            'estimate_cardinality' => 'not-a-cardinality',
            'tags' => [],
            'classification_note' => null,
            'page_count_estimate' => null,
        ],
        'decision' => [
            'disposition' => 'ignore',
            'reason' => 'unknown_type',
            'extraction_dispatched' => false,
        ],
    ]))->toThrow(InvalidArgumentException::class);
});
