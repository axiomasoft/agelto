<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\Ai\AiDocumentType;

test('гидрирует полный JSON-ответ скрининга сметы', function (): void {
    $result = ScreeningResult::from([
        'document_type' => 'estimate',
        'confidence' => 0.92,
        'description' => 'Локальный сметный расчёт на ремонт кровли, 12 страниц',
        'tags' => ['Москва', 'ОС', 'ФЕР', 'Кровельные работы'],
        'page_count_estimate' => 12,
        'ignore_reason' => null,
    ]);

    expect($result->document_type)->toBe(AiDocumentType::Estimate)
        ->and($result->confidence)->toBe(0.92)
        ->and($result->description)->toBe('Локальный сметный расчёт на ремонт кровли, 12 страниц')
        ->and($result->tags)->toBe(['Москва', 'ОС', 'ФЕР', 'Кровельные работы'])
        ->and($result->page_count_estimate)->toBe(12)
        ->and($result->ignore_reason)->toBeNull();
});

test('гидрирует минимальный JSON-ответ скрининга без опциональных полей', function (): void {
    $result = ScreeningResult::from([
        'document_type' => 'unknown',
        'confidence' => 0.1,
    ]);

    expect($result->document_type)->toBe(AiDocumentType::Unknown)
        ->and($result->confidence)->toBe(0.1)
        ->and($result->description)->toBeNull()
        ->and($result->tags)->toBe([])
        ->and($result->page_count_estimate)->toBeNull()
        ->and($result->ignore_reason)->toBeNull();
});

test('гидрирует ответ с причиной игнорирования нерелевантного документа', function (): void {
    $result = ScreeningResult::from([
        'document_type' => 'protocol',
        'confidence' => 0.85,
        'ignore_reason' => 'Протокол рассмотрения заявок — не смета',
    ]);

    expect($result->document_type)->toBe(AiDocumentType::Protocol)
        ->and($result->ignore_reason)->toBe('Протокол рассмотрения заявок — не смета');
});
