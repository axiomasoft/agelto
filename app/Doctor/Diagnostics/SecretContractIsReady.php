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
        $requiredSecrets = [
            config(key: 'app.key'),
            config(key: 'database.connections.pgsql.password'),
            config(key: 'database.redis.default.password'),
            config(key: 'filesystems.disks.s3.secret'),
        ];

        foreach ($requiredSecrets as $secret) {
            if (! is_string($secret) || trim($secret) === '') {
                return $this->fail('missing');
            }
        }

        $wireDigestKey = config(key: 'proxyma.wire.digest_key');
        if (! is_string($wireDigestKey) || strlen($wireDigestKey) < 32 || trim($wireDigestKey) === '') {
            return $this->fail('wire_digest_key');
        }

        return $this->pass('ready');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'Required secrets are configured without exposing values.',
            'missing' => 'A required secret is unavailable. Run make secrets:init and verify Compose secret grants.',
            'wire_digest_key' => 'Configure PROXYMA_WIRE_DIGEST_KEY with at least 32 bytes. Run make secrets:init and verify Compose secret grants.',
        ];
    }
}
