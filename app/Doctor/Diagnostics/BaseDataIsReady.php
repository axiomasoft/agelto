<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use App\Services\Health\ReleaseStateReadiness;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class BaseDataIsReady extends Diagnostic
{
    public string $name = 'Base data is ready';

    public string $group = 'agelto-release';

    public function __construct(private readonly ReleaseStateReadiness $readiness)
    {
        parent::__construct();
    }

    public function check(): DiagnosticResult
    {
        return $this->readiness->baseDataIsReady() ? $this->pass('ready') : $this->fail('missing');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'All BaseSeeder graph nodes and the prompt canon are present.',
            'missing' => 'Base data is incomplete or drifted. Run make prod:seed-base after migrations.',
        ];
    }
}
