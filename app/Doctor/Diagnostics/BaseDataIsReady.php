<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use App\Models\Procurement\Platform\Platform;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class BaseDataIsReady extends Diagnostic
{
    public string $name = 'Base data is ready';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        return Platform::query()->exists()
            ? $this->pass('ready')
            : $this->warn('missing');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'Base application data is present.',
            'missing' => 'Base application data is missing. Run make prod:seed-base after migrations.',
        ];
    }
}
