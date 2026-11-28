<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\EstimateHeaderMap;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Services\Llm\Schema\PromptContextGenerator;

test('блок не несёт исходника контрактов — структура уехала в исходящую схему (шаг 3)', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([RowsChunkResponse::class, EstimateHeaderMap::class]);

    // Маркер печати исходника v1 и его синтаксические следы: ни одного из них в системной части
    // больше нет — второй носитель структуры снят.
    foreach (['// --- Source:', 'final class', 'public function __construct', 'extends Data', '#[Description', '#[Alias'] as $marker) {
        expect($block)->not->toContain($marker);
    }
});

test('синонимы #[Alias] замыкания доезжают до блока — и словаря, и контрактов ответа', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([RowsChunkResponse::class, EstimateHeaderMap::class]);

    // Словарь, объявленный ТИПОМ (обход от обёртки до него не достаёт).
    expect($block)->toContain('EstimateHeaderMap:')
        ->and($block)->toContain('- row_index: «№ п/п», «№», «поз», «Номер», «по порядку», «Номер п/п», «№№ п/п»');

    // Замыкание обёртки ответа: свойства позиции и кейсы enum'а — единственное содержимое
    // печатавшегося исходника, которого исходящая схема не переносит.
    expect($block)->toContain('EstimateRow:')
        ->and($block)->toContain('- index_code: «Индекс», «Ин»')
        ->and($block)->toContain('ResourceType:')
        ->and($block)->toContain('- labor: «ЗП», «ОТ», «ЗПМ», «Трудозатраты», «чел.час», «ОТ(ЗТ)», «ОТм(ЗТм)»');
});

test('контракт без единого #[Alias] даёт пустой блок, а не заголовок без содержимого', function (): void {
    $generator = new PromptContextGenerator;

    expect($generator->generate([ScreeningResult::class]))->toBe('')
        ->and($generator->generate([TotalsResponse::class]))->toBe('')
        ->and($generator->generate([]))->toBe('');
});

test('классы идут по короткому имени, свойства — в порядке конструктора (единый порядок)', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([PassportResponse::class, EstimateHeaderMap::class]);

    preg_match_all('/^(\S+):$/m', $block, $classMatches);
    expect($classMatches[1])->toBe(['EstimateHeaderMap', 'PassportResponse']);

    // Порядок свойств словаря — конструкторный (row_index → code → name → …), не алфавитный:
    // тот же единый порядок, в котором свойства эмитятся в исходящую схему.
    preg_match_all('/^- (\w+):/m', mb_substr($block, (int) mb_strpos($block, 'EstimateHeaderMap:'), (int) mb_strpos($block, 'PassportResponse:') - (int) mb_strpos($block, 'EstimateHeaderMap:')), $propertyMatches);
    expect($propertyMatches[1])->toBe(['row_index', 'code', 'name', 'unit', 'quantity', 'total_price']);
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
