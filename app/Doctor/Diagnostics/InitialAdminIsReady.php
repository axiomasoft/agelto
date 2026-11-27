<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use App\Services\Health\ReleaseStateReadiness;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class InitialAdminIsReady extends Diagnostic
{
    public string $name = 'Initial administrator is ready';

    public string $group = 'agelto-release';

    public function __construct(private readonly ReleaseStateReadiness $readiness)
    {
        parent::__construct();
    }

    public function check(): DiagnosticResult
    {
        return $this->readiness->initialAdminIsReady()
            ? $this->pass('ready')
            : $this->fail('missing');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'An initial SuperAdmin exists.',
            'missing' => 'Initial SuperAdmin is missing. Use admin:create-initial with a password file.',
        ];
    }
}
