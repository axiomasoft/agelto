<?php

declare(strict_types=1);

use Illuminate\Console\Command;

test('Doctor fails with the shared application readiness diagnostic', function (): void {
    $this->artisan('doctor', [
        '--format' => 'agent',
        '--only' => 'agelto-readiness',
        '--no-interaction' => true,
    ])->assertExitCode(Command::FAILURE);
});
