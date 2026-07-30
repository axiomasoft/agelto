<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\EstimateHeaderMap;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Services\Llm\Schema\PromptContextGenerator;

test('генерирует промт-блок ScreeningResult байт-в-байт как фикстура', function (): void {
    $generator = new PromptContextGenerator;

    $block = $generator->generate([ScreeningResult::class]);

    $expected = file_get_contents(base_path('tests/Fixtures/Llm/screening-result.prompt.txt'));

    expect($block)->toBe($expected)
        ->and($block)->not->toContain('<?php')
        ->and($block)->not->toContain('namespace ')
        ->and($block)->not->toContain('use Spatie\LaravelData\Data;')
        ->and($block)->toStartWith('// --- Source: ScreeningResult.php ---');
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
