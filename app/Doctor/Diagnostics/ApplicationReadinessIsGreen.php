<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use App\Services\Health\ApplicationReadiness;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class ApplicationReadinessIsGreen extends Diagnostic
{
    public string $name = 'Application readiness is green';

    public string $group = 'agelto-readiness';

    public function __construct(private readonly ApplicationReadiness $readiness)
    {
        parent::__construct();
    }

    public function check(): DiagnosticResult
    {
        return $this->readiness->isGreen() ? $this->pass('ready') : $this->fail('degraded');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'All active application readiness checks passed.',
            'degraded' => 'An active application readiness check failed. Run app:readiness --format=json.',
        ];
    }
}
