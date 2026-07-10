<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\PromptStep;
use App\Models\Procurement\Ai\Prompt;
use App\Services\Llm\PromptComposer;

function promptComposer(): PromptComposer
{
    return app(PromptComposer::class);
}

test('compose() склеивает global → step-global → step в порядке priority ASC', function (): void {
    Prompt::factory()->create(['key' => 'step-rule', 'step' => PromptStep::ScreeningClassify->value, 'content' => 'C: правило шага', 'priority' => 0, 'version' => 1]);
    Prompt::factory()->create(['key' => 'global-rule', 'step' => PromptStep::Global->value, 'content' => 'A: общее правило', 'priority' => 0, 'version' => 1]);
    Prompt::factory()->create(['key' => 'screening-global-rule', 'step' => PromptStep::ScreeningGlobal->value, 'content' => 'B: правило скрининга', 'priority' => 0, 'version' => 1]);

    $composed = promptComposer()->compose(PromptStep::ScreeningClassify);

    expect($composed->systemPart)->toBe("A: общее правило\n\n---\n\nB: правило скрининга\n\n---\n\nC: правило шага");
});

test('compose() внутри одного шага сортирует по priority ASC', function (): void {
    Prompt::factory()->create(['key' => 'second', 'step' => PromptStep::Global->value, 'content' => 'второе', 'priority' => 5, 'version' => 1]);
    Prompt::factory()->create(['key' => 'first', 'step' => PromptStep::Global->value, 'content' => 'первое', 'priority' => 1, 'version' => 1]);

    $composed = promptComposer()->compose(PromptStep::ExtractionGlobal);

    expect($composed->systemPart)->toBe("первое\n\n---\n\nвторое");
});

test('деактивированное правило выпадает из композиции и из prompt_version_set', function (): void {
    Prompt::factory()->create(['key' => 'active-rule', 'step' => PromptStep::Global->value, 'content' => 'активное', 'priority' => 0, 'version' => 1, 'is_active' => true]);
    Prompt::factory()->create(['key' => 'inactive-rule', 'step' => PromptStep::Global->value, 'content' => 'неактивное', 'priority' => 1, 'version' => 1, 'is_active' => false]);

    $composed = promptComposer()->compose(PromptStep::ExtractionGlobal);

    expect($composed->systemPart)->toBe('активное')
        ->and($composed->promptVersionSet)->toBe(['active-rule' => 1])
        ->and($composed->promptVersionSet)->not->toHaveKey('inactive-rule');
});

test('у ключа берётся максимальная version среди активных', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => PromptStep::Global->value, 'content' => 'версия 1', 'priority' => 0, 'version' => 1]);
    Prompt::factory()->create(['key' => 'rule', 'step' => PromptStep::Global->value, 'content' => 'версия 2', 'priority' => 0, 'version' => 2]);

    $composed = promptComposer()->compose(PromptStep::ExtractionGlobal);

    expect($composed->systemPart)->toBe('версия 2')
        ->and($composed->promptVersionSet)->toBe(['rule' => 2]);
});

test('compose() добавляет блок схем от PromptContextGenerator после правил', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => PromptStep::Global->value, 'content' => 'правило', 'priority' => 0, 'version' => 1]);

    $composed = promptComposer()->compose(PromptStep::ExtractionGlobal, [ScreeningResult::class]);

    expect($composed->systemPart)->toStartWith('правило'.PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL.'// --- Source: ScreeningResult.php ---');
});

test('compose() без правил и без схем возвращает пустую системную часть с детерминированным rules_hash', function (): void {
    $composed = promptComposer()->compose(PromptStep::ExtractionTotals);

    expect($composed->systemPart)->toBe('')
        ->and($composed->rulesHash)->toBe(sha1(''))
        ->and($composed->promptVersionSet)->toBe([]);
});

test('compose() детерминирован между вызовами (база rules_hash/implicit-cache)', function (): void {
    Prompt::factory()->create(['key' => 'rule', 'step' => PromptStep::Global->value, 'content' => 'правило', 'priority' => 0, 'version' => 1]);

    $first = promptComposer()->compose(PromptStep::ExtractionGlobal);
    $second = promptComposer()->compose(PromptStep::ExtractionGlobal);

    expect($first->systemPart)->toBe($second->systemPart)
        ->and($first->rulesHash)->toBe($second->rulesHash);
});
