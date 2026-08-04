<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use Illuminate\Support\Facades\Storage;

/*
 * `storage:disk-backfill` (P6.5, `research/07-storage-addressing.md` §6): материализация `null` →
 * фактическое имя диска (`--materialize-only`) и перенос с верификацией sha256/размера
 * (`--to=<disk>`) — обновление записи ТОЛЬКО после совпадения на приёмнике, resumable через
 * `--from-id`, идемпотентно.
 */

beforeEach(function (): void {
    Storage::fake('documents_v1');
    Storage::fake('documents_v2');
    config(['crawler.documents_disk' => 'documents_v1']);
});

test('перенос копирует объект потоком и обновляет диск записи после совпадения хэша', function (): void {
    $content = 'содержимое версии';
    Storage::disk('documents_v1')->put('documents/aa/hash', $content);
    $version = DocumentVersion::factory()->onDisk('documents_v1')->create([
        'storage_path' => 'documents/aa/hash',
        'content_hash' => hash('sha256', $content),
    ]);

    $this->artisan('storage:disk-backfill', [
        'carrier' => 'document-versions',
        '--to' => 'documents_v2',
    ])->assertExitCode(0);

    Storage::disk('documents_v2')->assertExists('documents/aa/hash');
    expect($version->fresh()->disk)->toBe('documents_v2');
});

test('повторный прогон переноса идемпотентен: расхождений нет, диск не меняется повторно', function (): void {
    $content = 'идемпотентное содержимое';
    Storage::disk('documents_v1')->put('documents/bb/hash', $content);
    $version = DocumentVersion::factory()->onDisk('documents_v1')->create([
        'storage_path' => 'documents/bb/hash',
        'content_hash' => hash('sha256', $content),
    ]);

    $this->artisan('storage:disk-backfill', ['carrier' => 'document-versions', '--to' => 'documents_v2'])
        ->assertExitCode(0);
    expect($version->fresh()->disk)->toBe('documents_v2');

    $this->artisan('storage:disk-backfill', ['carrier' => 'document-versions', '--to' => 'documents_v2'])
        ->assertExitCode(0);
    expect($version->fresh()->disk)->toBe('documents_v2');
});

test('прерванный прогон продолжается с --from-id: более ранние записи не трогаются повторно', function (): void {
    $contentA = 'первая запись';
    $contentB = 'вторая запись';
    Storage::disk('documents_v1')->put('documents/cc/a', $contentA);
    Storage::disk('documents_v1')->put('documents/cc/b', $contentB);

    $first = DocumentVersion::factory()->onDisk('documents_v1')->create([
        'storage_path' => 'documents/cc/a',
        'content_hash' => hash('sha256', $contentA),
    ]);
    $second = DocumentVersion::factory()->onDisk('documents_v1')->create([
        'storage_path' => 'documents/cc/b',
        'content_hash' => hash('sha256', $contentB),
    ]);

    $this->artisan('storage:disk-backfill', [
        'carrier' => 'document-versions',
        '--to' => 'documents_v2',
        '--from-id' => $second->id,
    ])->assertExitCode(0);

    expect($first->fresh()->disk)->toBe('documents_v1')
        ->and($second->fresh()->disk)->toBe('documents_v2');
    Storage::disk('documents_v2')->assertMissing('documents/cc/a');
});

test('подменённый объект на приёмнике даёт расхождение: запись не обновляется, код возврата ненулевой', function (): void {
    $content = 'исходное содержимое';
    Storage::disk('documents_v1')->put('documents/dd/hash', $content);
    Storage::disk('documents_v2')->put('documents/dd/hash', 'подменённое содержимое');

    $version = DocumentVersion::factory()->onDisk('documents_v1')->create([
        'storage_path' => 'documents/dd/hash',
        'content_hash' => hash('sha256', $content),
    ]);

    $this->artisan('storage:disk-backfill', ['carrier' => 'document-versions', '--to' => 'documents_v2'])
        ->assertExitCode(1);

    expect($version->fresh()->disk)->toBe('documents_v1')
        ->and(Storage::disk('documents_v2')->get('documents/dd/hash'))->toBe('подменённое содержимое');
});

test('materialize-only проставляет диск и не пишет ни одного объекта', function (): void {
    $version = DocumentVersion::factory()->create(['disk' => null, 'storage_path' => 'documents/ee/hash']);

    $this->artisan('storage:disk-backfill', [
        'carrier' => 'document-versions',
        '--materialize-only' => true,
    ])->assertExitCode(0);

    expect($version->fresh()->disk)->toBe('documents_v1');
    Storage::disk('documents_v1')->assertMissing('documents/ee/hash');
    Storage::disk('documents_v2')->assertMissing('documents/ee/hash');
});

test('перенос estimate-raw-results считает эталон с источника — колонки хэша у носителя нет', function (): void {
    $content = '{"rows":[]}';
    Storage::disk('documents_v1')->put('ai/estimates/1/1.json', $content);

    $estimate = Estimate::factory()->onDisk('documents_v1')->create(['raw_result_ref' => 'ai/estimates/1/1.json']);
    $withoutRef = Estimate::factory()->create(['raw_result_ref' => null, 'raw_result_disk' => null]);

    $this->artisan('storage:disk-backfill', [
        'carrier' => 'estimate-raw-results',
        '--to' => 'documents_v2',
    ])->assertExitCode(0);

    expect($estimate->fresh()->raw_result_disk)->toBe('documents_v2')
        ->and($withoutRef->fresh()->raw_result_disk)->toBeNull();
    Storage::disk('documents_v2')->assertExists('ai/estimates/1/1.json');
});
