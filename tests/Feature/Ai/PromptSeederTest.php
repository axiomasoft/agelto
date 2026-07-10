<?php

declare(strict_types=1);

use App\Enums\Procurement\PromptStep;
use App\Models\Procurement\Ai\Prompt;
use Database\Seeders\PromptSeeder;

test('сидер создаёт по одной активной protected-записи на каждое значение PromptStep', function (): void {
    (new PromptSeeder)->run();

    expect(Prompt::query()->count())->toBe(count(PromptStep::cases()));

    foreach (PromptStep::cases() as $step) {
        $prompt = Prompt::query()->where('step', $step->value)->where('is_active', true)->sole();

        expect($prompt->is_protected)->toBeTrue()
            ->and($prompt->version)->toBe(1)
            ->and($prompt->content)->not->toBe('');
    }
});

test('сидер идемпотентен — повторный запуск не плодит записей', function (): void {
    (new PromptSeeder)->run();
    (new PromptSeeder)->run();

    expect(Prompt::query()->count())->toBe(count(PromptStep::cases()));
});

test('деактивация исчезнувшего файла — is_active=false, не delete', function (): void {
    $ghost = Prompt::factory()->create([
        'key' => 'ghost:vanished',
        'step' => PromptStep::Global->value,
        'content' => 'этого файла больше нет в resources/ai/prompts',
        'version' => 1,
        'priority' => 0,
        'is_active' => true,
        'is_protected' => true,
    ]);

    (new PromptSeeder)->run();

    $ghost->refresh();
    expect(Prompt::query()->whereKey($ghost->id)->exists())->toBeTrue()
        ->and($ghost->is_active)->toBeFalse();
});

test('поднятая версия деактивирует старую версию того же key, не удаляя её', function (): void {
    $old = Prompt::factory()->create([
        'key' => 'extraction:global',
        'step' => PromptStep::ExtractionGlobal->value,
        'content' => 'устаревший текст версии 0',
        'version' => 0,
        'priority' => 0,
        'is_active' => true,
        'is_protected' => true,
    ]);

    (new PromptSeeder)->run();

    $old->refresh();
    expect(Prompt::query()->whereKey($old->id)->exists())->toBeTrue()
        ->and($old->is_active)->toBeFalse();

    $current = Prompt::query()->where('key', 'extraction:global')->where('version', 1)->sole();
    expect($current->is_active)->toBeTrue();
});

test('сидер не трогает операторские (не-protected) записи', function (): void {
    $operator = Prompt::factory()->create([
        'key' => 'operator:extra-hint',
        'step' => PromptStep::ScreeningClassify->value,
        'content' => 'корректирующий промт оператора',
        'version' => 1,
        'priority' => 5,
        'is_active' => true,
        'is_protected' => false,
    ]);

    (new PromptSeeder)->run();

    $operator->refresh();
    expect($operator->is_active)->toBeTrue()
        ->and($operator->is_protected)->toBeFalse()
        ->and($operator->content)->toBe('корректирующий промт оператора');
});
