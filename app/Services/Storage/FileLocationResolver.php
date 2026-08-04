<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;

/**
 * Единственная точка «по записи дай диск и путь» / «на какой диск класть НОВЫЙ объект»
 * (P6.3, `research/07-storage-addressing.md` §4.2). Read-половина фолбэкает `null` в колонке
 * диска на конфиг-ключ носителя (D51 — фолбэк снимает материализация P6.5, не код). Write-половина
 * (`current*Disk()`) — единственный легальный читатель конфиг-ключей диска в проекте
 * (`tests/Feature/Storage/StorageAddressingGateTest.php`).
 */
final readonly class FileLocationResolver
{
    public function forDocumentVersion(DocumentVersion $version): FileLocation
    {
        return new FileLocation(
            disk: $version->disk ?? $this->currentDocumentsDisk(),
            path: $version->storage_path,
        );
    }

    public function forRepresentation(DocumentRepresentation $representation): FileLocation
    {
        return new FileLocation(
            disk: $representation->disk ?? $this->currentDocumentsDisk(),
            path: $representation->storage_path,
        );
    }

    public function forFixture(Fixture $fixture): FileLocation
    {
        return new FileLocation(
            disk: $fixture->disk ?? $this->currentFixturesDisk(),
            path: $fixture->storage_path,
        );
    }

    public function currentDocumentsDisk(): string
    {
        return (string) config('crawler.documents_disk');
    }

    public function currentFixturesDisk(): string
    {
        return (string) config('ai.fixtures.disk');
    }

    public function currentRawDisk(): string
    {
        return (string) config('crawler.raw_disk');
    }
}
