<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\RepresentationWriter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

dataset('prepared representation outputs', [
    'scan pdf' => [RepresentationKind::PdfOcr],
    'scan text' => [RepresentationKind::Text],
    'word pdf' => [RepresentationKind::Pdf],
    'spreadsheet pdf' => [RepresentationKind::PdfAlt],
    'spreadsheet text grid' => [RepresentationKind::TextGrid],
    'gsfx structured payload' => [RepresentationKind::StructuredXml],
]);

test('false object write does not finalize any prepared representation', function (RepresentationKind $kind): void {
    $version = DocumentVersion::factory()->create();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('files')->once()->with('prepared')->andReturn([]);
    $disk->shouldReceive('writeStream')->once()->with(
        Mockery::pattern('/\.staging\./'),
        Mockery::on(static fn (mixed $stream): bool => is_resource($stream)
            && stream_get_contents($stream) === 'payload'),
    )->andReturnFalse();
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnFalse();

    expect(fn () => app(RepresentationWriter::class)->write(
        disk: $disk,
        version: $version,
        kind: $kind,
        path: 'prepared/failure',
        content: 'payload',
        attributes: representationAttributes(),
    ))->toThrow(RuntimeException::class);

    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
})->with('prepared representation outputs');

test('storage exception does not finalize a representation', function (): void {
    $version = DocumentVersion::factory()->create();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('files')->once()->with('prepared')->andReturn([]);
    $disk->shouldReceive('writeStream')->once()->with(
        Mockery::pattern('/\.staging\./'),
        Mockery::on(static fn (mixed $stream): bool => is_resource($stream)
            && stream_get_contents($stream) === 'payload'),
    )->andThrow(new RuntimeException('disk unavailable'));
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnFalse();

    expect(fn () => app(RepresentationWriter::class)->write(
        disk: $disk,
        version: $version,
        kind: RepresentationKind::Pdf,
        path: 'prepared/exception',
        content: 'payload',
        attributes: representationAttributes(),
    ))->toThrow(RuntimeException::class, 'disk unavailable');

    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

test('streamed representation uses stream IO for upload and verification', function (): void {
    $version = DocumentVersion::factory()->create();
    $content = '%PDF-streamed';
    $storagePath = representationStoragePath(path: 'prepared/stream.pdf', content: $content);
    $source = fopen('php://temp', 'w+b');
    expect($source)->toBeResource();

    fwrite($source, $content);
    rewind($source);
    $disk = Storage::fake('representation-stream');

    try {
        $representation = app(RepresentationWriter::class)->writeStream(
            disk: $disk,
            version: $version,
            kind: RepresentationKind::PdfOcr,
            path: 'prepared/stream.pdf',
            stream: $source,
            attributes: [...representationAttributes(), 'disk' => 'representation-stream', 'size_bytes' => 1, 'mime' => 'application/pdf'],
        );
    } finally {
        fclose($source);
    }

    expect($representation->storage_path)->toBe($storagePath)
        ->and($representation->size_bytes)->toBe(strlen($content))
        ->and($disk->get($storagePath))->toBe($content);
});

test('retry after database finalization failure converges on the newly generated object', function (): void {
    $version = DocumentVersion::factory()->create();
    $disk = Storage::fake('representation-retry');
    $writer = app(RepresentationWriter::class);
    $path = 'prepared/retry.pdf';
    $firstContent = '%PDF-first';
    $secondContent = '%PDF-second';
    $firstStoragePath = representationStoragePath(path: $path, content: $firstContent);
    $secondStoragePath = representationStoragePath(path: $path, content: $secondContent);

    expect(fn () => $writer->write(
        disk: $disk,
        version: $version,
        kind: RepresentationKind::Pdf,
        path: $path,
        content: $firstContent,
        attributes: [...representationAttributes(), 'disk' => 'representation-retry', 'mime' => null],
    ))->toThrow(QueryException::class);

    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
    $disk->assertExists($firstStoragePath);

    $representation = $writer->write(
        disk: $disk,
        version: $version,
        kind: RepresentationKind::Pdf,
        path: $path,
        content: $secondContent,
        attributes: [...representationAttributes(), 'disk' => 'representation-stream', 'disk' => 'representation-retry', 'size_bytes' => 1, 'mime' => 'application/pdf'],
    );

    expect($representation->storage_path)->toBe($secondStoragePath)
        ->and($representation->size_bytes)->toBe(strlen($secondContent))
        ->and($disk->get($representation->storage_path))->toBe($secondContent)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
});

test('retry repairs a corrupt pre-existing content-addressed object through staging', function (): void {
    $version = DocumentVersion::factory()->create();
    $disk = Storage::fake('representation-corrupt');
    $content = '%PDF-complete';
    $path = 'prepared/corrupt.pdf';
    $storagePath = representationStoragePath(path: $path, content: $content);
    $disk->put($storagePath, '%PDF-partial');

    $representation = app(RepresentationWriter::class)->write(
        disk: $disk,
        version: $version,
        kind: RepresentationKind::Pdf,
        path: $path,
        content: $content,
        attributes: [...representationAttributes(), 'disk' => 'representation-corrupt', 'mime' => 'application/pdf'],
    );

    expect($disk->get($storagePath))->toBe($content)
        ->and($representation->storage_path)->toBe($storagePath)
        ->and(collect($disk->allFiles())->contains(fn (string $file): bool => str_contains($file, '.staging.')))->toBeFalse();
});

function representationStoragePath(string $path, string $content): string
{
    $contentHash = hash('sha256', $content);
    $extension = pathinfo($path, PATHINFO_EXTENSION);

    if ($extension === '') {
        return "{$path}.{$contentHash}";
    }

    return substr($path, 0, -strlen($extension) - 1).".{$contentHash}.{$extension}";
}

/** @return array<string, mixed> */
function representationAttributes(): array
{
    return [
        'storage_path' => 'prepared/failure',
        'disk' => 's3',
        'size_bytes' => 7,
        'mime' => 'text/plain',
        'meta' => [],
        'generated_at' => now(),
    ];
}
