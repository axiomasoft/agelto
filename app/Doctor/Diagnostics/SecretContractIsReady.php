<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class SecretContractIsReady extends Diagnostic
{
    public string $name = 'Secret contract is ready';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        foreach (['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'AWS_SECRET_ACCESS_KEY'] as $key) {
            if (! is_string(config(key: 'app.key')) && $key === 'APP_KEY') {
                return $this->fail('missing');
            }
        }

        return $this->pass('ready');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'Required secrets are configured without exposing values.',
            'missing' => 'A required secret is unavailable. Run make secrets:init and verify Compose secret grants.',
        ];
    }
}
