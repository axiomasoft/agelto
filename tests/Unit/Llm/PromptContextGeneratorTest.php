<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\EstimateHeaderMap;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Services\Llm\Schema\PromptContextGenerator;

test('генерирует промт-блок ScreeningResult байт-в-байт как фикстура', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([ScreeningResult::class]);

    $expected = file_get_contents(base_path('tests/Fixtures/Llm/screening-result.prompt.txt'));

    expect($block)->toBe($expected)
        ->and($block)->not->toContain('<?php')
        ->and($block)->not->toContain('namespace ')
        ->and($block)->not->toContain('use Spatie\LaravelData\Data;')
        ->and($block)->toStartWith('// --- Source: AiDocumentType.php ---');
});

test('печатает транзитивное замыкание корня, а не переданный список (D51)', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([RowsChunkResponse::class]);

    // Обёртка ответа шага строк не несёт ни одного докблока позиции сама по себе: без замыкания
    // модель получала 1.2 КБ `RowsChunkResponse` и ничего больше (находка F4 аудита P7 EST-TRAIN).
    foreach (['EstimateRow', 'IncompleteRow', 'RowResource', 'RowAttributes', 'RowModifier', 'RowType', 'ResourceType'] as $nested) {
        expect($block)->toContain("// --- Source: {$nested}.php ---");
    }

    // Обёртка по-прежнему последняя (композиция v1, D5) — замыкание порядок не ломает.
    expect(mb_strpos($block, '// --- Source: RowsChunkResponse.php ---'))
        ->toBeGreaterThan((int) mb_strpos($block, '// --- Source: EstimateRow.php ---'));
});

test('самоссылающийся тип не зацикливает обход (TotalLine.children)', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([TotalsResponse::class]);

    expect(mb_substr_count($block, '// --- Source: TotalLine.php ---'))->toBe(1);
});

test('ставит Response-обёртки последними независимо от порядка входа (композиция v1, D5)', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([PassportResponse::class, EstimateHeaderMap::class]);

    $headerMapPos = mb_strpos($block, '// --- Source: EstimateHeaderMap.php ---');
    $passportResponsePos = mb_strpos($block, '// --- Source: PassportResponse.php ---');

    expect($headerMapPos)->not->toBeFalse()
        ->and($passportResponsePos)->not->toBeFalse()
        ->and($headerMapPos)->toBeLessThan($passportResponsePos);
});

test('вывод детерминирован между запусками (база rules_hash и implicit-cache префикса)', function (): void {
    $generator = new PromptContextGenerator;

    $classes = [PassportResponse::class, EstimateHeaderMap::class, ScreeningResult::class];

    expect($generator->generate($classes))->toBe($generator->generate($classes));
});

test('rulesHash — стабильный sha1 системной части, чувствительный к содержимому', function (): void {
    $generator = new PromptContextGenerator;

    $hash = $generator->rulesHash('система: правило A');

    expect($hash)->toBe(sha1('система: правило A'))
        ->and(mb_strlen($hash))->toBe(40)
        ->and($generator->rulesHash('система: правило A'))->toBe($hash)
        ->and($generator->rulesHash('система: правило B'))->not->toBe($hash);
});
