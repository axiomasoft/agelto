<?php

declare(strict_types=1);

use App\Enums\Llm\CorePromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Models\Llm\Prompt;
use Database\Seeders\PromptSeeder;

test('сидер создаёт по одной активной protected-записи на каждое значение PromptStep', function (): void {
    app(PromptSeeder::class)->run();

    expect(Prompt::query()->count())->toBe(count([...CorePromptStep::cases(), ...EstimatePromptStep::cases()]));

    foreach ([...CorePromptStep::cases(), ...EstimatePromptStep::cases()] as $step) {
        $prompt = Prompt::query()->where('step', $step->value)->where('is_active', true)->sole();

        expect($prompt->is_protected)->toBeTrue()
            ->and($prompt->version)->toBeGreaterThanOrEqual(1)
            ->and($prompt->content)->not->toBe('');
    }
});

test('сидер идемпотентен — повторный запуск не плодит записей', function (): void {
    app(PromptSeeder::class)->run();
    app(PromptSeeder::class)->run();

    expect(Prompt::query()->count())->toBe(count([...CorePromptStep::cases(), ...EstimatePromptStep::cases()]));
});

test('деактивация исчезнувшего файла — is_active=false, не delete', function (): void {
    $ghost = Prompt::factory()->create([
        'key' => 'ghost:vanished',
        'step' => CorePromptStep::Global->value,
        'content' => 'этого файла больше нет в resources/ai/prompts',
        'version' => 1,
        'priority' => 0,
        'is_active' => true,
        'is_protected' => true,
    ]);

    app(PromptSeeder::class)->run();

    $ghost->refresh();
    expect(Prompt::query()->whereKey($ghost->id)->exists())->toBeTrue()
        ->and($ghost->is_active)->toBeFalse();
});

test('поднятая версия деактивирует старую версию того же key, не удаляя её', function (): void {
    $old = Prompt::factory()->create([
        'key' => 'extraction:global',
        'step' => EstimatePromptStep::ExtractionGlobal->value,
        'content' => 'устаревший текст версии 0',
        'version' => 0,
        'priority' => 0,
        'is_active' => true,
        'is_protected' => true,
    ]);

    app(PromptSeeder::class)->run();

    $old->refresh();
    expect(Prompt::query()->whereKey($old->id)->exists())->toBeTrue()
        ->and($old->is_active)->toBeFalse();

    $current = Prompt::query()->where('key', 'extraction:global')->where('is_active', true)->sole();
    expect($current->version)->toBeGreaterThan($old->version);
});

test('сидер не трогает операторские (не-protected) записи', function (): void {
    $operator = Prompt::factory()->create([
        'key' => 'operator:extra-hint',
        'step' => CorePromptStep::ScreeningClassify->value,
        'content' => 'корректирующий промт оператора',
        'version' => 1,
        'priority' => 5,
        'is_active' => true,
        'is_protected' => false,
    ]);

    app(PromptSeeder::class)->run();

    $operator->refresh();
    expect($operator->is_active)->toBeTrue()
        ->and($operator->is_protected)->toBeFalse()
        ->and($operator->content)->toBe('корректирующий промт оператора');
});
