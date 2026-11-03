<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Автомат фолбэка на окно cutover (P6.4, `research/07-storage-addressing.md` §5): диск записи →
 * при отсутствии объекта — цепочка от новых поколений к старым, ровно один `exists()` на диск.
 * Read-only, никакого copy-on-read (D52).
 */

beforeEach(function (): void {
    Storage::fake('documents_v2');
    Storage::fake('documents_v1');

    config([
        'documents.disk' => 'documents_v2',
        'documents.fallback_disks' => ['documents_v1'],
    ]);

    $this->resolver = new FileLocationResolver;
});

test('объект на диске записи — фолбэк не срабатывает, второй диск не трогается', function (): void {
    $version = DocumentVersion::factory()->onDisk('documents_v2')->create(['storage_path' => 'documents/aa/hash']);
    Storage::disk('documents_v2')->put('documents/aa/hash', 'содержимое');

    $location = $this->resolver->forDocumentVersion($version);

    expect($location->disk)->toBe('documents_v2')
        ->and($location->path)->toBe('documents/aa/hash');
    Storage::disk('documents_v1')->assertMissing('documents/aa/hash');
});

test('объект только в цепочке — резолвер находит его на старом диске и логирует промах', function (): void {
    Log::spy();

    $version = DocumentVersion::factory()->onDisk('documents_v2')->create(['storage_path' => 'documents/bb/hash']);
    Storage::disk('documents_v1')->put('documents/bb/hash', 'содержимое');

    $location = $this->resolver->forDocumentVersion($version);

    expect($location->disk)->toBe('documents_v1')
        ->and($location->path)->toBe('documents/bb/hash');
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['carrier'] === 'document-version'
            && $context['record_id'] === $version->id
            && $context['recorded_disk'] === 'documents_v2'
            && $context['fallback_disk'] === 'documents_v1',
    );
});

test('объекта нет нигде — резолвер возвращает адрес записи без исключения', function (): void {
    $version = DocumentVersion::factory()->onDisk('documents_v2')->create(['storage_path' => 'documents/cc/hash']);

    $location = $this->resolver->forDocumentVersion($version);

    expect($location->disk)->toBe('documents_v2')
        ->and($location->path)->toBe('documents/cc/hash');
});

test('пустая цепочка — ко второму диску обращения нет вовсе', function (): void {
    config(['documents.fallback_disks' => []]);

    $version = DocumentVersion::factory()->onDisk('documents_v2')->create(['storage_path' => 'documents/dd/hash']);
    Storage::disk('documents_v1')->put('documents/dd/hash', 'содержимое, которое фолбэк не обязан найти');

    $location = $this->resolver->forDocumentVersion($version);

    expect($location->disk)->toBe('documents_v2')
        ->and($location->path)->toBe('documents/dd/hash');
});

test('forFixture использует свою цепочку fallback-дисков эталонов', function (): void {
    Storage::fake('fixtures_v2');
    Storage::fake('fixtures_v1');
    config([
        'ai.fixtures.disk' => 'fixtures_v2',
        'ai.fixtures.fallback_disks' => ['fixtures_v1'],
    ]);

    $fixture = Fixture::factory()->onDisk('fixtures_v2')->create(['storage_path' => 'fixtures/ee/hash']);
    Storage::disk('fixtures_v1')->put('fixtures/ee/hash', 'содержимое');

    $location = $this->resolver->forFixture($fixture);

    expect($location->disk)->toBe('fixtures_v1')
        ->and($location->path)->toBe('fixtures/ee/hash');
});
