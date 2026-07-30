<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Exceptions\Procurement\OperationFailed;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use App\Services\Procurement\Fixture\FixtureVerifier;
use Illuminate\Console\Command;

/**
 * Сверка трёх носителей корпуса (P2.4, гейт целостности D1): манифест (git) ↔ файлы на диске ↔
 * записи реестра `prc_ai_fixtures`. Verify — диагност, а не ремонтник: расхождение только
 * сообщается, ничего не правится. Чтение манифеста и сверка вынесены в
 * {@see FixtureManifestReader}/{@see FixtureVerifier} (P3.3).
 */
final class FixturesVerifyCommand extends Command
{
    protected $signature = 'estimate:fixtures:verify
        {--manifest= : путь к manifest.json (дефолт config(ai.fixtures.manifest_path))}
        {--json : машинный вывод}';

    protected $description = 'Сверить manifest.json ↔ хранилище ↔ реестр prc_ai_fixtures';

    public function __construct(
        private readonly FixtureManifestReader $manifestReader,
        private readonly FixtureVerifier $verifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifestPath = (string) ($this->option('manifest') ?? config('ai.fixtures.manifest_path'));
        $disk = (string) config('ai.fixtures.disk');

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
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
}
