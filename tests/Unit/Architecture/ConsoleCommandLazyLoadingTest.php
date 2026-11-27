<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;

/*
 * Laravel instantiates every console command without #[AsCommand] on each artisan boot, together
 * with its constructor dependency graph. The attribute registers the command lazily by name, so
 * one command's dependencies cannot slow down or break unrelated artisan calls.
 */

test('каждая консольная команда приложения регистрируется лениво под именем из сигнатуры', function (): void {
    $violations = [];
    $checked = 0;

    foreach (app(Kernel::class)->all() as $name => $command) {
        $class = $command::class;

        if (! str_starts_with($class, 'App\\') || ! $command instanceof Command) {
            continue;
        }

        $checked++;
        $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);
        $declared = $attributes === [] ? null : $attributes[0]->newInstance()->name;

        if ($declared !== $command->getName()) {
            $violations[] = sprintf('%s: #[AsCommand] %s, signature %s', $class, $declared ?? 'missing', $name);
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});
