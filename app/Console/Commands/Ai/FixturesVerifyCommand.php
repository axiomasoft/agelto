<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Dto\Procurement\Ai\Fixture\FixtureManifestEntry;
use App\Repositories\Procurement\FixtureStoreRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Сверка трёх носителей корпуса (P2.4, гейт целостности D1): манифест (git) ↔ файлы на диске ↔
 * записи реестра `prc_ai_fixtures`. Verify — диагност, а не ремонтник: расхождение только
 * сообщается, ничего не правится.
 */
final class FixturesVerifyCommand extends Command
{
    protected $signature = 'ai:fixtures:verify
        {--manifest= : путь к manifest.json (дефолт config(ai.fixtures.manifest_path))}
        {--json : машинный вывод}';

    protected $description = 'Сверить manifest.json ↔ хранилище ↔ реестр prc_ai_fixtures';

    public function __construct(private readonly FixtureStoreRepository $fixtures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifestPath = (string) ($this->option('manifest') ?? config('ai.fixtures.manifest_path'));
        $disk = (string) config('ai.fixtures.disk');

        if (! is_file($manifestPath)) {
            $this->error("Манифест не найден: {$manifestPath}");

            return self::FAILURE;
        }

        try {
            $decoded = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Манифест не распарсен: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($decoded) || ! is_array($decoded['fixtures'] ?? null)) {
            $this->error('Ожидается объект вида {"version": 1, "fixtures": [...]}');

            return self::FAILURE;
        }

        $rows = [];
        $manifestCodes = [];
        $mismatches = 0;

        foreach (array_values($decoded['fixtures']) as $index => $entryJson) {
            $verdict = $this->verifyEntry($entryJson, $index, $disk);
            $rows[] = $verdict;
            if (is_array($entryJson) && isset($entryJson['code'])) {
                $manifestCodes[] = (string) $entryJson['code'];
            }
            if ($verdict['ok'] === false) {
                $mismatches++;
            }
        }

        $registryOrphans = $this->fixtures->all()
            ->pluck('code')
            ->reject(fn (string $code): bool => in_array($code, $manifestCodes, strict: true))
            ->values()
            ->all();

        $diskOrphans = $this->findDiskOrphans($disk, $manifestCodes);
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

        $this->info("ok: {$this->countOk($rows)} · расхождений: {$mismatches} · лишних: {$orphans}");

        return $mismatches + $orphans > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{code: string, manifest_valid: bool, disk_ok: bool, registry_ok: bool, gold_ok: bool, ok: bool}
     */
    private function verifyEntry(mixed $entryJson, int $index, string $disk): array
    {
        $code = is_array($entryJson) ? (string) ($entryJson['code'] ?? "#{$index}") : "#{$index}";

        if (! is_array($entryJson)) {
            return ['code' => $code, 'manifest_valid' => false, 'disk_ok' => false, 'registry_ok' => false, 'gold_ok' => false, 'ok' => false];
        }

        try {
            $entry = FixtureManifestEntry::validateAndCreate($entryJson);
        } catch (ValidationException|CannotCreateData) {
            return ['code' => $code, 'manifest_valid' => false, 'disk_ok' => false, 'registry_ok' => false, 'gold_ok' => false, 'ok' => false];
        }

        $diskOk = Storage::disk($disk)->exists($entry->storage_path)
            && Storage::disk($disk)->size($entry->storage_path) === $entry->size_bytes
            && hash('sha256', Storage::disk($disk)->get($entry->storage_path)) === $entry->sha256;

        $registryFixture = $this->fixtures->findByCode($entry->code);
        $registryOk = $registryFixture !== null
            && $registryFixture->sha256 === $entry->sha256
            && $registryFixture->storage_path === $entry->storage_path
            && $registryFixture->document_type === $entry->document_type
            && $registryFixture->owner_document_type === $entry->owner_document_type
            && $registryFixture->profileClass() === $entry->class
            && $registryFixture->profileForm() === $entry->form
            && $registryFixture->profileGoldSource() === $entry->gold_source
            && $registryFixture->quirks->all() === $entry->quirks;

        $goldOk = $entry->gold_path === null || is_file(base_path($entry->gold_path));

        return [
            'code' => $code,
            'manifest_valid' => true,
            'disk_ok' => $diskOk,
            'registry_ok' => $registryOk,
            'gold_ok' => $goldOk,
            'ok' => $diskOk && $registryOk && $goldOk,
        ];
    }

    /**
     * @param  list<string>  $manifestCodes
     * @return list<string>
     */
    private function findDiskOrphans(string $disk, array $manifestCodes): array
    {
        $orphans = [];

        foreach (Storage::disk($disk)->directories() as $directory) {
            $code = basename($directory);

            if (! in_array($code, $manifestCodes, strict: true)) {
                $orphans[] = $code;
            }
        }

        return $orphans;
    }

    /**
     * @param  list<array{ok: bool}>  $rows
     */
    private function countOk(array $rows): int
    {
        return count(array_filter($rows, static fn (array $row): bool => $row['ok']));
    }
}
