<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use Illuminate\Support\Facades\Process;
use JsonException;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use stdClass;

final class PrivatePackagesArePinned extends Diagnostic
{
    private const array PackageVersions = [
        'azguard' => 'dev-main',
        'proxyma' => '0.1.0',
    ];

    public string $name = 'Private packages are pinned';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        $path = base_path(path: 'docker/private-vendors.lock.json');
        $manifest = is_file($path) ? @file_get_contents($path) : false;

        if (! is_string($manifest) || ($repositories = $this->readClosedManifest($manifest)) === null) {
            return $this->fail('missing');
        }

        if (app()->environment('production')) {
            $attestation = @file_get_contents('/usr/share/agelto/vendor-lock.sha');

            return is_string($attestation)
                && hash_equals(hash('sha256', $manifest), trim($attestation))
                && $this->packagesAreInstalled($repositories)
                ? $this->pass('pinned')
                : $this->fail('missing');
        }

        $environment = ['GIT_CONFIG_COUNT' => (string) count($repositories)];
        foreach (array_keys($repositories) as $index => $key) {
            $environment["GIT_CONFIG_KEY_{$index}"] = 'safe.directory';
            $environment["GIT_CONFIG_VALUE_{$index}"] = base_path(path: "local/vendors/{$key}");
        }

        return Process::env($environment)
            ->run(['bash', base_path(path: 'docker/scripts/vendor-sync.sh'), '--check'])->successful()
            ? $this->pass('pinned')
            : $this->fail('missing');
    }

    /** @return array<string, list<string>>|null */
    private function readClosedManifest(string $manifest): ?array
    {
        try {
            $value = json_decode(json: $manifest, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $value instanceof stdClass
            || count(get_object_vars($value)) !== 2
            || ($value->version ?? null) !== 2
            || ! is_array($value->repositories ?? null)
            || count($value->repositories) !== count(self::PackageVersions)) {
            return null;
        }

        $repositories = [];
        foreach ($value->repositories as $row) {
            if (! $row instanceof stdClass || count(get_object_vars($row)) !== 4) {
                return null;
            }

            $key = $row->key ?? null;
            $repository = $row->repository ?? null;
            $commit = $row->commit ?? null;
            $packages = $row->packages ?? null;

            if (! is_string($key) || ! isset(self::PackageVersions[$key]) || isset($repositories[$key])
                || ! $repository instanceof stdClass || count(get_object_vars($repository)) !== 2
                || ($repository->host ?? null) !== 'github.com'
                || ($repository->path ?? null) !== "axiomasoft/{$key}"
                || ! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/', $commit) !== 1
                || $commit === str_repeat('0', 40)
                || ! is_array($packages)) {
                return null;
            }

            $names = $this->packageNames(packages: $packages, key: $key);
            if ($names === null) {
                return null;
            }

            $repositories[$key] = $names;
        }

        return $repositories;
    }

    /**
     * @param  array<mixed>  $packages
     * @return list<string>|null
     */
    private function packageNames(array $packages, string $key): ?array
    {
        $expectedNames = [
            'packages/core' => "axiomasoft/{$key}",
            'packages/filament' => "axiomasoft/{$key}-filament",
        ];

        $names = [];
        foreach ($packages as $package) {
            if (! $package instanceof stdClass || count(get_object_vars($package)) !== 3) {
                return null;
            }

            $path = $package->path ?? null;
            if (! is_string($path) || ! isset($expectedNames[$path])
                || ($package->name ?? null) !== $expectedNames[$path]
                || ($package->version ?? null) !== self::PackageVersions[$key]) {
                return null;
            }

            $names[] = $expectedNames[$path];
            unset($expectedNames[$path]);
        }

        return $expectedNames === [] ? $names : null;
    }

    /** @param array<string, list<string>> $repositories */
    private function packagesAreInstalled(array $repositories): bool
    {
        foreach ($repositories as $packages) {
            foreach ($packages as $name) {
                if (! is_dir(base_path(path: "vendor/{$name}"))) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function messages(): array
    {
        return [
            'pinned' => 'Private packages match the closed manifest and build attestation.',
            'missing' => 'Private package pin is invalid or unavailable. Run make vendor:sync and rebuild the release image.',
        ];
    }
}
