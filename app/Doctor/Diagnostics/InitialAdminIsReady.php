<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use App\Models\User;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class InitialAdminIsReady extends Diagnostic
{
    public string $name = 'Initial administrator is ready';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        return User::query()->get()->contains(fn (User $user): bool => $user->isSuperAdmin())
            ? $this->pass('ready')
            : $this->warn('missing');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'An initial SuperAdmin exists.',
            'missing' => 'Initial SuperAdmin is missing. Use admin:create-initial with a password file.',
        ];
    }
}
