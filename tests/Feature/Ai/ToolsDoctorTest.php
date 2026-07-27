<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/**
 * P3.2 — `ai:tools:doctor` диагностирует наличие/версию системных бинарей. Пути берутся из
 * конфига и подменяются `Process::fake()` — тест не зависит от реально установленных бинарей.
 */
test('doctor зелёный, когда все бинари на месте', function (): void {
    Process::fake([
        '*' => Process::result(output: "tool version 1.2.3\n"),
    ]);

    $this->artisan('ai:tools:doctor')->assertExitCode(0);
});

test('doctor красный и называет бинарь, если его нет', function (): void {
    Process::fake([
        '*soffice*' => Process::result(exitCode: 127, errorOutput: 'command not found'),
        '*' => Process::result(output: "tool version 1.2.3\n"),
    ]);

    $this->artisan('ai:tools:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('не найден');
});

test('doctor --json печатает машинный объект с версией и вердиктом', function (): void {
    Process::fake([
        '*' => Process::result(output: "tool version 1.2.3\n"),
    ]);

    $exitCode = $this->artisan('ai:tools:doctor --json')->run();

    expect($exitCode)->toBe(0);
});
