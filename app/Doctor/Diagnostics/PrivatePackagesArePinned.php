<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class PrivatePackagesArePinned extends Diagnostic
{
    public string $name = 'Private packages are pinned';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        return is_file(base_path(path: 'docker/private-vendors.lock.json'))
            ? $this->pass('pinned')
            : $this->fail('missing');
    }

    protected function messages(): array
    {
        return [
            'pinned' => 'Private package lock is present.',
            'missing' => 'Private package lock is missing. Run make vendor:sync and commit the verified lock.',
        ];
    }
}
