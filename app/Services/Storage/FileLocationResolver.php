<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Enums\Telemetry\LogChannel;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Резолвит disk/path записи и текущий disk новых объектов. Null disk читается из
 * конфигурации носителя до материализации. При отсутствии объекта проверяются предыдущие
 * поколения disks; driver exception пробрасывается, fallback запускается только по exists=false.
 * Write-методы current*Disk() централизуют чтение конфигурации адреса новых объектов.
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
        return $this->diskConfig('documents.disk');
    }

    public function currentFixturesDisk(): string
    {
        return $this->diskConfig('ai.fixtures.disk');
    }

    public function currentRawDisk(): string
    {
        return $this->diskConfig('acquisition.raw_disk');
    }

    /**
     * Read-only поиск: disk записи, затем fallback disks от новых поколений к старым.
     * На каждый disk приходится один exists(); копирование и обновление записи здесь не выполняются.
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
                LogChannel::Storage->warning('Резолвер адреса: объект найден в фолбэк-цепочке дисков', [
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
        return $this->fallbackDisks('documents.fallback_disks');
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
