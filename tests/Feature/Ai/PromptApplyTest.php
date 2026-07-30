<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\User;

/**
 * P7.4 — `ai:prompts:apply`: работает ТОЛЬКО со `status=approved` (D8). Тесты, пишущие в реальные
 * файлы-канон (не фейковый диск — README «файл под git — канон») в ОБОИХ корнях D32 (ядро
 * `resources/ai/prompts/**`, домен `resources/estimates/ai/prompts/**`), ОБЯЗАНЫ восстановить
 * исходное содержимое в `finally`, иначе рабочее дерево репозитория останется грязным после
 * прогона тестов.
 */
function promptApplyTargetPath(): string
{
    return base_path('resources/estimates/ai/prompts/extraction-totals/0-extraction-totals.md');
}

/**
 * Ключ `global` живёт в корне ЯДРА (не доменном) — сценарий доказывает, что `PromptPatchApplier`
 * находит файл по `key` в ОБОИХ корнях объединённого пространства (D32), а не только в доменном,
 * где живёт `extraction-totals` выше (Validation 6 P3.4).
 */
function promptApplyCoreTargetPath(): string
{
    return base_path('resources/ai/prompts/global/0-global.md');
}

test('apply на pending отказывается и не трогает файл', function (): void {
    $original = file_get_contents(promptApplyTargetPath());

    $suggestion = PromptSuggestion::factory()->create([
        'prompt_key' => 'extraction:totals',
        'prompt_step' => 'extraction:totals',
        'base_version' => 2,
        'proposed_content' => 'Текст, который не должен попасть в файл.',
    ]);

    $this->artisan('ai:prompts:apply', ['id' => $suggestion->id])->assertExitCode(1);

    expect(file_get_contents(promptApplyTargetPath()))->toBe($original)
        ->and($suggestion->refresh()->status->value)->toBe('pending');
});

test('apply на approved (prompt_patch) поднимает версию, сохраняет поля, ai:prompts:sync --check зелёный', function (): void {
    $original = file_get_contents(promptApplyTargetPath());

    try {
        $suggestion = PromptSuggestion::factory()->approved()->create([
            'prompt_key' => 'extraction:totals',
            'prompt_step' => 'extraction:totals',
            'base_version' => 2,
            'proposed_content' => 'Обновлённый (тестовый) текст правил итогов сметы.',
        ]);

        $this->artisan('ai:prompts:apply', ['id' => $suggestion->id])->assertExitCode(0);

        $rewritten = file_get_contents(promptApplyTargetPath());
        expect($rewritten)->toContain('key: extraction:totals')
            ->toContain('step: extraction:totals')
            ->toContain('version: 3')
            ->toContain('priority: 0')
            ->toContain('is_active: true')
            ->toContain('Обновлённый (тестовый) текст правил итогов сметы.');

        expect($suggestion->refresh()->status->value)->toBe('applied')
            ->and($suggestion->applied_version)->toBe(3);

        $this->artisan('ai:prompts:sync')->assertExitCode(0);
        $this->artisan('ai:prompts:sync', ['--check' => true])->assertExitCode(0);
    } finally {
        file_put_contents(promptApplyTargetPath(), $original);
    }
});

test('apply на approved (prompt_patch) находит файл кросс-документного ключа в корне ядра, не только в доменном', function (): void {
    $original = file_get_contents(promptApplyCoreTargetPath());

    try {
        $suggestion = PromptSuggestion::factory()->approved()->create([
            'prompt_key' => 'global',
            'prompt_step' => 'global',
            'base_version' => 2,
            'proposed_content' => 'Обновлённый (тестовый) текст глобального правила.',
        ]);

        $this->artisan('ai:prompts:apply', ['id' => $suggestion->id])->assertExitCode(0);

        $rewritten = file_get_contents(promptApplyCoreTargetPath());
        expect($rewritten)->toContain('key: global')
            ->toContain('version: 3')
            ->toContain('Обновлённый (тестовый) текст глобального правила.');

        expect($suggestion->refresh()->status->value)->toBe('applied')
            ->and($suggestion->applied_version)->toBe(3);

        $this->artisan('ai:prompts:sync')->assertExitCode(0);
        $this->artisan('ai:prompts:sync', ['--check' => true])->assertExitCode(0);
    } finally {
        file_put_contents(promptApplyCoreTargetPath(), $original);
    }
});

test('apply для correction_hint создаёт prc_ai_corrections(is_reusable=true) и не трогает файлы', function (): void {
    $original = file_get_contents(promptApplyTargetPath());
    $analysis = Analysis::factory()->create();
    $user = User::factory()->create();

    $suggestion = PromptSuggestion::factory()->approved()->correctionHint()->create([
        'analysis_id' => $analysis->id,
        'decided_by' => $user->id,
        'hint' => 'Коды ресурсов ОТ(ЗТ) и ОТм(ЗТм) — трудозатраты, не материалы.',
        'document_type' => 'estimate',
    ]);

    $correctionsBefore = Correction::query()->count();

    $this->artisan('ai:prompts:apply', ['id' => $suggestion->id])->assertExitCode(0);

    expect(Correction::query()->count())->toBe($correctionsBefore + 1);

    $correction = Correction::query()->latest('id')->first();
    expect($correction->is_reusable)->toBeTrue()
        ->and($correction->hint)->toBe('Коды ресурсов ОТ(ЗТ) и ОТм(ЗТм) — трудозатраты, не материалы.')
        ->and($correction->analysis_id)->toBe($analysis->id)
        ->and($correction->created_by)->toBe($user->id);

    expect($suggestion->refresh()->status->value)->toBe('applied')
        ->and($suggestion->applied_version)->toBeNull()
        ->and(file_get_contents(promptApplyTargetPath()))->toBe($original);
});

test('apply --dry-run на approved показывает план и не меняет ни файл, ни статус', function (): void {
    $original = file_get_contents(promptApplyTargetPath());

    $suggestion = PromptSuggestion::factory()->approved()->create([
        'prompt_key' => 'extraction:totals',
        'prompt_step' => 'extraction:totals',
        'base_version' => 2,
        'proposed_content' => 'Текст, который НЕ должен попасть в файл при dry-run.',
    ]);

    $this->artisan('ai:prompts:apply', ['id' => $suggestion->id, '--dry-run' => true])
        ->expectsOutputToContain('dry-run')
        ->assertExitCode(0);

    expect(file_get_contents(promptApplyTargetPath()))->toBe($original)
        ->and($suggestion->refresh()->status->value)->toBe('approved');
});
