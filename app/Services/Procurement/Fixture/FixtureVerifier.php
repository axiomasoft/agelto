<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Dto\Procurement\Ai\Fixture\FixtureManifestEntry;
use App\Repositories\Procurement\FixtureStoreRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Сверка трёх носителей корпуса (`estimate:fixtures:verify`, D1, P2.4): манифест (git) ↔ файлы на диске
 * ↔ записи реестра `prc_ai_fixtures`. Диагност, а не ремонтник — расхождение только сообщается.
 */
final readonly class FixtureVerifier
{
    public function __construct(private FixtureStoreRepository $fixtures) {}

    /**
     * @param  array{version: int, fixtures: list<mixed>}  $manifest
     * @return array{
     *     rows: list<array{code: string, manifest_valid: bool, disk_ok: bool, registry_ok: bool, gold_ok: bool, ok: bool}>,
     *     registry_orphans: list<string>,
     *     disk_orphans: list<string>,
     * }
     */
    public function verify(array $manifest, string $disk): array
    {
        $rows = [];
        $manifestCodes = [];

        foreach ($manifest['fixtures'] as $index => $entryJson) {
            $rows[] = $this->verifyEntry($entryJson, $index, $disk);

            if (is_array($entryJson) && isset($entryJson['code'])) {
                $manifestCodes[] = (string) $entryJson['code'];
            }
        }

        $registryOrphans = $this->fixtures->all()
            ->pluck('code')
            ->reject(fn (string $code): bool => in_array($code, $manifestCodes, strict: true))
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'registry_orphans' => $registryOrphans,
            'disk_orphans' => $this->findDiskOrphans($disk, $manifestCodes),
        ];
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
}
