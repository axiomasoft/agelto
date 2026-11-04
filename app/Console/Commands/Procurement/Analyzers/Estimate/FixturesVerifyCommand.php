<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Exceptions\Procurement\OperationFailed;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use App\Services\Procurement\Fixture\FixtureVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Сверка трёх носителей корпуса (гейт целостности): манифест (git) ↔ файлы на диске ↔
 * записи реестра `prc_ai_fixtures`. Verify — диагност, а не ремонтник: расхождение только
 * сообщается, ничего не правится. Чтение манифеста и сверка вынесены в
 * {@see FixtureManifestReader}/{@see FixtureVerifier}.
 */
#[Description('Сверить manifest.json ↔ хранилище ↔ реестр prc_ai_fixtures')]
#[Signature('estimate:fixtures:verify
        {--manifest= : путь к manifest.json (дефолт config(ai.fixtures.manifest_path))}
        {--json : машинный вывод}')]
final class FixturesVerifyCommand extends Command
{
    public function __construct(
        private readonly FixtureManifestReader $manifestReader,
        private readonly FixtureVerifier $verifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifestPath = $this->manifestPath();
        $disk = $this->fixturesDisk();

        try {
            $manifest = $this->manifestReader->read($manifestPath);
        } catch (OperationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $this->verifier->verify($manifest, $disk);
        $rows = $result['rows'];
        $registryOrphans = $result['registry_orphans'];
        $diskOrphans = $result['disk_orphans'];
        $mismatches = count(array_filter($rows, static fn (array $row): bool => $row['ok'] === false));
        $orphans = count($registryOrphans) + count($diskOrphans);

        if ($this->option('json')) {
            $this->line(json_encode([
                'rows' => $rows,
                'registry_orphans' => $registryOrphans,
                'disk_orphans' => $diskOrphans,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['код', 'манифест', 'файл', 'реестр', 'вердикт'],
                array_map(static fn (array $row): array => [
                    $row['code'],
                    $row['manifest_valid'] ? 'ok' : 'invalid',
                    $row['disk_ok'] ? 'ok' : 'mismatch',
                    $row['registry_ok'] ? 'ok' : 'mismatch',
                    $row['ok'] ? 'ok' : 'РАСХОЖДЕНИЕ',
                ], $rows),
            );

            foreach ($registryOrphans as $code) {
                $this->error("orphan в реестре (нет в манифесте): {$code}");
            }

            foreach ($diskOrphans as $code) {
                $this->error("orphan на диске (нет в манифесте): {$code}");
            }
        }

        $this->info('ok: '.($this->countOk($rows)).' · расхождений: '.$mismatches.' · лишних: '.$orphans);

        return $mismatches + $orphans > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array{ok: bool}>  $rows
     */
    private function countOk(array $rows): int
    {
        return count(array_filter($rows, static fn (array $row): bool => $row['ok']));
    }

    private function manifestPath(): string
    {
        $manifestPath = $this->option('manifest');

        if (is_string($manifestPath) && $manifestPath !== '') {
            return $manifestPath;
        }

        $configuredPath = config('ai.fixtures.manifest_path');

        if (! is_string($configuredPath) || $configuredPath === '') {
            throw new \UnexpectedValueException('ai.fixtures.manifest_path must be a non-empty string.');
        }

        return $configuredPath;
    }

    private function fixturesDisk(): string
    {
        $disk = config('ai.fixtures.disk');

        if (! is_string($disk) || $disk === '') {
            throw new \UnexpectedValueException('ai.fixtures.disk must be a non-empty string.');
        }

        return $disk;
    }
}
