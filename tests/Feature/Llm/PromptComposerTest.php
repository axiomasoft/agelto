<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\EstimateHeaderMap;
use App\Enums\Llm\CorePromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Models\Llm\Prompt;
use App\Services\Llm\PromptComposer;

function promptComposer(): PromptComposer
{
    return app(PromptComposer::class);
}

test('compose() склеивает global → step-global → step в порядке priority ASC', function (): void {
    Prompt::factory()->create(['key' => 'step-rule', 'step' => CorePromptStep::ScreeningClassify->value, 'content' => 'C: правило шага', 'priority' => 0, 'version' => 1]);
    Prompt::factory()->create(['key' => 'global-rule', 'step' => CorePromptStep::Global->value, 'content' => 'A: общее правило', 'priority' => 0, 'version' => 1]);
    Prompt::factory()->create(['key' => 'screening-global-rule', 'step' => CorePromptStep::ScreeningGlobal->value, 'content' => 'B: правило скрининга', 'priority' => 0, 'version' => 1]);

    $composed = promptComposer()->compose(CorePromptStep::ScreeningClassify);

    expect($composed->systemPart)->toBe("A: общее правило\n\n---\n\nB: правило скрининга\n\n---\n\nC: правило шага");
});

test('compose() внутри одного шага сортирует по priority ASC', function (): void {
    Prompt::factory()->create(['key' => 'second', 'step' => CorePromptStep::Global->value, 'content' => 'второе', 'priority' => 5, 'version' => 1]);
    Prompt::factory()->create(['key' => 'first', 'step' => CorePromptStep::Global->value, 'content' => 'первое', 'priority' => 1, 'version' => 1]);

    $composed = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal);

    expect($composed->systemPart)->toBe("первое\n\n---\n\nвторое");
});

test('деактивированное правило выпадает из композиции и из prompt_version_set', function (): void {
    Prompt::factory()->create(['key' => 'active-rule', 'step' => CorePromptStep::Global->value, 'content' => 'активное', 'priority' => 0, 'version' => 1, 'is_active' => true]);
    Prompt::factory()->create(['key' => 'inactive-rule', 'step' => CorePromptStep::Global->value, 'content' => 'неактивное', 'priority' => 1, 'version' => 1, 'is_active' => false]);

    $composed = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal);

    expect($composed->systemPart)->toBe('активное')
        ->and($composed->promptVersionSet)->toBe(['active-rule' => 1])
        ->and($composed->promptVersionSet)->not->toHaveKey('inactive-rule');
});

test('у ключа берётся максимальная version среди активных', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => CorePromptStep::Global->value, 'content' => 'версия 1', 'priority' => 0, 'version' => 1]);
    Prompt::factory()->create(['key' => 'rule', 'step' => CorePromptStep::Global->value, 'content' => 'версия 2', 'priority' => 0, 'version' => 2]);

    $composed = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal);

    expect($composed->systemPart)->toBe('версия 2')
        ->and($composed->promptVersionSet)->toBe(['rule' => 2]);
});

test('compose() добавляет блок словарей синонимов после правил', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => CorePromptStep::Global->value, 'content' => 'правило', 'priority' => 0, 'version' => 1]);

    $composed = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal, [EstimateHeaderMap::class]);

    expect($composed->systemPart)
        ->toStartWith('правило'.PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL.'Словари синонимов.')
        ->and($composed->systemPart)->toContain('EstimateHeaderMap:');
});

test('контракт без синонимов не добавляет пустого разделителя к правилам', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => CorePromptStep::Global->value, 'content' => 'правило', 'priority' => 0, 'version' => 1]);

    // `ScreeningResult` не несёт ни одного `#[Alias]`: блок словарей пуст, и системная часть
    // обязана остаться ровно правилами — иначе хвостовой разделитель молча менял бы rules_hash.
    $composed = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal, [ScreeningResult::class]);

    expect($composed->systemPart)->toBe('правило');
});

test('compose() без правил и без схем возвращает пустую системную часть с детерминированным rules_hash', function (): void {
    $composed = promptComposer()->compose(EstimatePromptStep::ExtractionTotals);

    expect($composed->systemPart)->toBe('')
        ->and($composed->rulesHash)->toBe(sha1(''))
        ->and($composed->promptVersionSet)->toBe([]);
});

test('compose() детерминирован между вызовами (база rules_hash/implicit-cache)', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => CorePromptStep::Global->value, 'content' => 'правило', 'priority' => 0, 'version' => 1]);

    $first = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal);
    $second = promptComposer()->compose(EstimatePromptStep::ExtractionGlobal);

    expect($first->systemPart)->toBe($second->systemPart)
        ->and($first->rulesHash)->toBe($second->rulesHash);
});
