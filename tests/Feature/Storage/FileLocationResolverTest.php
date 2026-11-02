<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Storage\FileLocationResolver;

beforeEach(function (): void {
    config([
        'crawler.documents_disk' => 'documents_v1',
        'ai.fixtures.disk' => 'fixtures_v1',
        'crawler.raw_disk' => 'raw_v1',
    ]);

    $this->resolver = new FileLocationResolver;
});

test('forDocumentVersion использует обязательный записанный диск canonical версии', function (): void {
    $version = DocumentVersion::factory()->create(['disk' => app(FileLocationResolver::class)->currentDocumentsDisk(), 'storage_path' => 'documents/aa/hash']);

    $location = $this->resolver->forDocumentVersion($version);

    expect($location->disk)->toBe('documents_v1')
        ->and($location->path)->toBe('documents/aa/hash');
});

test('forDocumentVersion берёт диск из записи, если он материализован', function (): void {
    $version = DocumentVersion::factory()->onDisk('documents_v2')->create(['storage_path' => 'documents/bb/hash']);

    $location = $this->resolver->forDocumentVersion($version);

    expect($location->disk)->toBe('documents_v2');
});

test('forRepresentation использует обязательный записанный диск canonical представления', function (): void {
    $representation = DocumentRepresentation::factory()->create(['disk' => app(FileLocationResolver::class)->currentDocumentsDisk(), 'storage_path' => 'documents/aa/hash.prepared.pdf']);

    $location = $this->resolver->forRepresentation($representation);

    expect($location->disk)->toBe('documents_v1')
        ->and($location->path)->toBe('documents/aa/hash.prepared.pdf');
});

test('forRepresentation берёт диск из записи, если он материализован', function (): void {
    $representation = DocumentRepresentation::factory()->create(['disk' => 'documents_v2']);

    $location = $this->resolver->forRepresentation($representation);

    expect($location->disk)->toBe('documents_v2');
});

test('forFixture фолбэкает на текущий диск эталонов при пустой колонке', function (): void {
    $fixture = Fixture::factory()->create(['disk' => null]);

    $location = $this->resolver->forFixture($fixture);

    expect($location->disk)->toBe('fixtures_v1')
        ->and($location->path)->toBe($fixture->storage_path);
});

test('forFixture берёт диск из записи, если он материализован', function (): void {
    $fixture = Fixture::factory()->onDisk('fixtures_v2')->create();

    $location = $this->resolver->forFixture($fixture);

    expect($location->disk)->toBe('fixtures_v2');
});

test('write-аксессоры читают текущие конфиг-ключи диска, каждый свой', function (): void {
    expect($this->resolver->currentDocumentsDisk())->toBe('documents_v1')
        ->and($this->resolver->currentFixturesDisk())->toBe('fixtures_v1')
        ->and($this->resolver->currentRawDisk())->toBe('raw_v1');
});

test('write-аксессоры отклоняют нетипизированное имя диска', function (): void {
    config(['crawler.documents_disk' => 42]);

    expect(fn (): string => $this->resolver->currentDocumentsDisk())
        ->toThrow(LogicException::class, 'должен быть непустым именем диска');
});

test('fallback-цепочка отклоняет нетипизированное имя диска', function (): void {
    config(['crawler.documents_fallback_disks' => ['documents_v0', 42]]);
    $version = DocumentVersion::factory()->create(['storage_path' => 'documents/aa/hash']);

    expect(fn () => $this->resolver->forDocumentVersion($version))
        ->toThrow(LogicException::class, 'должен быть списком непустых имён дисков');
});
