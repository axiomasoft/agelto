<?php

declare(strict_types=1);

use Illuminate\Console\Command;

test('выводит P2 readiness diagnostics в agent формате', function () {
    $this->artisan('doctor', ['--format' => 'agent', '--only' => 'agelto-release', '--no-interaction' => true])
        ->assertExitCode(Command::SUCCESS);
});
