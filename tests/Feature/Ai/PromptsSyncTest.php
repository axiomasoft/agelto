<?php

declare(strict_types=1);

use App\Enums\Llm\CorePromptStep;
use App\Models\Llm\Prompt;

/**
 * P3.2 — `ai:prompts:sync` бьёт через `artisan(...)` (Implementation Rule 7): разбор канона и
 * запись уже покрыты `PromptSeederTest` (через `PromptCanonSynchronizer::sync()`), здесь
 * проверяется только команда — флаги, коды возврата, печать.
 */
test('без --check синхронизирует канон и печатает подтверждение', function (): void {
    expect(Prompt::query()->count())->toBe(0);

    $this->artisan('ai:prompts:sync')
        ->assertExitCode(0)
        ->expectsOutputToContain('Промты синхронизированы.');

    expect(Prompt::query()->count())->toBeGreaterThan(0);
});

test('--check на актуальном каноне — дрейф не обнаружен, ничего не пишет', function (): void {
    $this->artisan('ai:prompts:sync')->assertExitCode(0);
    $before = Prompt::query()->count();

    $this->artisan('ai:prompts:sync', ['--check' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Дрейф файлы↔БД не обнаружен.');

    expect(Prompt::query()->count())->toBe($before);
});

test('--check находит расхождение файл ≠ БД и завершается ошибкой', function (): void {
    $this->artisan('ai:prompts:sync')->assertExitCode(0);

    $prompt = Prompt::query()->where('is_active', true)->where('is_protected', true)->firstOrFail();
    $prompt->update(['content' => 'подменённый текст мимо канона файлов']);

    $this->artisan('ai:prompts:sync', ['--check' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('изменён:');
});

test('--check находит protected-запись, исчезнувшую из файлов', function (): void {
    $this->artisan('ai:prompts:sync')->assertExitCode(0);

    Prompt::factory()->create([
        'key' => 'ghost:vanished',
        'step' => CorePromptStep::Global->value,
        'content' => 'этого файла больше нет в resources/ai/prompts',
        'version' => 1,
        'priority' => 0,
        'is_active' => true,
        'is_protected' => true,
    ]);

    $this->artisan('ai:prompts:sync', ['--check' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('удалён из файлов, но активен в БД');
});
