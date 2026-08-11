<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use Illuminate\Support\Facades\Process;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class PrivatePackagesArePinned extends Diagnostic
{
    public string $name = 'Private packages are pinned';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        $manifest = base_path(path: 'docker/private-vendors.lock.json');
        $expectedHash = is_file($manifest) ? hash_file('sha256', $manifest) : false;

        if (! is_string($expectedHash) || ! $this->hasClosedManifest($manifest)) {
            return $this->fail('missing');
        }

        if (app()->environment('production')) {
            $attestation = @file_get_contents('/usr/share/agelto/vendor-lock.sha');

            return is_string($attestation)
                && hash_equals($expectedHash, trim($attestation))
                && $this->packagesAreInstalled()
                ? $this->pass('pinned')
                : $this->fail('missing');
        }

        return Process::env([
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => base_path(path: 'local/vendors/azguard'),
        ])->run(['bash', base_path(path: 'docker/scripts/vendor-sync.sh'), '--check'])->successful()
            ? $this->pass('pinned')
            : $this->fail('missing');
    }

    private function hasClosedManifest(string $manifest): bool
    {
        try {
            $value = json_decode((string) file_get_contents($manifest), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($value)
            || array_keys($value) !== ['version', 'repository', 'commit', 'packages']
            || $value['version'] !== 1
            || ! is_array($value['repository'])
            || array_keys($value['repository']) !== ['host', 'path']
            || ! is_string($value['repository']['host'])
            || ! is_string($value['repository']['path'])
            || ! is_string($value['commit'])
            || ! preg_match('/^[0-9a-f]{40}$/', $value['commit'])
            || ! is_array($value['packages'])
            || $value['packages'] === []) {
            return false;
        }

        foreach ($value['packages'] as $package) {
            if (! is_array($package)
                || array_keys($package) !== ['name', 'path']
                || ! is_string($package['name'])
                || ! is_string($package['path'])
                || ! preg_match('/^packages\/[A-Za-z0-9._-]+$/', $package['path'])) {
                return false;
            }
        }

        return true;
    }

    private function packagesAreInstalled(): bool
    {
        return is_dir(base_path(path: 'vendor/axioma-studio/azguard-core'))
            && is_dir(base_path(path: 'vendor/axioma-studio/azguard-filament'));
    }

    protected function messages(): array
    {
        return [
            'pinned' => 'Private packages match the closed manifest and build attestation.',
            'missing' => 'Private package pin is invalid or unavailable. Run make vendor:sync and rebuild the release image.',
        ];
    }
}
