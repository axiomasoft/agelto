<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\DocumentFormatInspector;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents\DocumentFormatContract;

test('contract dataset supplies valid and terminal mismatch evidence for every format', function (): void {
    foreach (DocumentFormatContract::cases() as $case) {
        expect($case['extensions'])->not->toBe([])
            ->and($case['mimes'])->not->toBe([])
            ->and($case['mismatch'])->not->toBeIn($case['mimes']);
    }
});

test('known extension with incompatible bytes is terminal format_mismatch', function (): void {
    Storage::fake('s3');
    $document = Document::factory()->create();
    $path = 'documents/contract-mismatch.pdf';
    Storage::disk('s3')->put($path, 'not a PDF');

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'filename' => 'contract.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $path,
    ]);

    $evidence = app(DocumentFormatInspector::class)->inspect($version);

    expect($evidence->reason)->toBe(UnprocessableReason::FormatMismatch)
        ->and($evidence->strategy)->toBeNull();
});

test('unknown extension is terminal unsupported_format before a handler can be selected', function (): void {
    $version = DocumentVersion::factory()->make([
        'filename' => 'contract.exe',
        'extension' => 'exe',
        'mime' => 'application/octet-stream',
    ]);

    $evidence = app(DocumentFormatInspector::class)->inspect($version);

    expect($evidence->reason)->toBe(UnprocessableReason::UnsupportedFormat)
        ->and($evidence->strategy)->toBeNull();
});
