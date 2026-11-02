<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Единственная точка «по записи дай диск и путь» / «на какой диск класть НОВЫЙ объект»
 * (P6.3, `research/07-storage-addressing.md` §4.2). Read-половина фолбэкает `null` в колонке
 * диска на конфиг-ключ носителя (D51 — фолбэк снимает материализация P6.5, не код), а затем
 * пробует конфиг-цепочку предыдущих поколений на окно cutover, если объекта нет на диске записи
 * (P6.4, §5): фолбэк реагирует ТОЛЬКО на `exists() === false`, исключение драйвера пробрасывается.
 * Write-половина (`current*Disk()`) — единственный легальный читатель конфиг-ключей диска в
 * проекте (`tests/Feature/Storage/StorageAddressingGateTest.php`).
 */
final readonly class FileLocationResolver
{
    public function forDocumentVersion(DocumentVersion $version): FileLocation
    {
        return $this->resolveWithFallback(
            carrier: 'document-version',
            recordId: $version->id,
            disk: $version->disk,
            path: $version->storage_path,
            fallbackDisks: $this->documentsFallbackDisks(),
        );
    }

    public function forRepresentation(DocumentRepresentation $representation): FileLocation
    {
        return $this->resolveWithFallback(
            carrier: 'representation',
            recordId: $representation->id,
            disk: $representation->disk,
            path: $representation->storage_path,
            fallbackDisks: $this->documentsFallbackDisks(),
        );
    }

    public function forFixture(Fixture $fixture): FileLocation
    {
        return $this->resolveWithFallback(
            carrier: 'fixture',
            recordId: $fixture->id,
            disk: $fixture->disk ?? $this->currentFixturesDisk(),
            path: $fixture->storage_path,
            fallbackDisks: $this->fixturesFallbackDisks(),
        );
    }

    public function currentDocumentsDisk(): string
    {
        return $this->diskConfig('crawler.documents_disk');
    }

    public function currentFixturesDisk(): string
    {
        return $this->diskConfig('ai.fixtures.disk');
    }

    public function currentRawDisk(): string
    {
        return $this->diskConfig('crawler.raw_disk');
    }

    /**
     * Автомат фолбэка (`research/07-storage-addressing.md` §5): диск записи → при отсутствии
     * объекта — цепочка от новых поколений к старым, ровно один `exists()` на диск. Read-only:
     * копирование, запись и обновление строки здесь не происходят (D52).
     *
     * @param  list<string>  $fallbackDisks
     */
    private function resolveWithFallback(string $carrier, int|string $recordId, string $disk, string $path, array $fallbackDisks): FileLocation
    {
        if ($fallbackDisks === []) {
            return new FileLocation(disk: $disk, path: $path);
        }

        if (Storage::disk($disk)->exists($path)) {
            return new FileLocation(disk: $disk, path: $path);
        }

        foreach ($fallbackDisks as $fallbackDisk) {
            if (Storage::disk($fallbackDisk)->exists($path)) {
                Log::warning('Резолвер адреса: объект найден в фолбэк-цепочке дисков', [
                    'carrier' => $carrier,
                    'record_id' => $recordId,
                    'recorded_disk' => $disk,
                    'fallback_disk' => $fallbackDisk,
                ]);

                return new FileLocation(disk: $fallbackDisk, path: $path);
            }
        }

        return new FileLocation(disk: $disk, path: $path);
    }

    /**
     * @return list<string>
     */
    private function documentsFallbackDisks(): array
    {
        return $this->fallbackDisks('crawler.documents_fallback_disks');
    }

    /**
     * @return list<string>
     */
    private function fixturesFallbackDisks(): array
    {
        return $this->fallbackDisks('ai.fixtures.fallback_disks');
    }

    private function diskConfig(string $key): string
    {
        $disk = config($key);

        if (! is_string($disk) || $disk === '') {
            throw new \LogicException("{$key} должен быть непустым именем диска");
        }

        return $disk;
    }

    /** @return list<string> */
    private function fallbackDisks(string $key): array
    {
        $configured = config($key, []);

        if (! is_array($configured)) {
            throw new \LogicException("{$key} должен быть списком имён дисков");
        }

        $disks = [];

        foreach ($configured as $disk) {
            if (! is_string($disk) || $disk === '') {
                throw new \LogicException("{$key} должен быть списком непустых имён дисков");
            }

            $disks[] = $disk;
        }

        return $disks;
    }
}
