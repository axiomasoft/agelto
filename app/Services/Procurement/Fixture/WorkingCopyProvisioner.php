<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\ShardedBlobPath;
use Illuminate\Support\Facades\Storage;

/**
 * Рабочая копия эталона (`external_id = "fixture:<CODE>"`, перенос блоба, идемпотентный резолв
 * версии) — ЕДИНСТВЕННЫЙ сейм (D41): подтверждено фактом кода (P7.2) для ДВУХ вызывающих —
 * консольного импорта эталонов и действия «Прогнать» на карточке эталона (P5.2). Курируемый оригинал (диск эталонов) и рабочая
 * копия (`documents.disk`) — сознательно РАЗНЫЕ носители (D19).
 */
final readonly class WorkingCopyProvisioner
{
    public function __construct(
        private FixtureStoreRepository $fixtures,
        private DocumentStoreRepository $documents,
        private FileLocationResolver $locationResolver,
    ) {}

    /**
     * @return array{0: Document, 1: DocumentVersion, 2: bool, 3: bool}
     */
    public function provision(Fixture $fixture, bool $dryRun): array
    {
        $fixturesDisk = $this->locationResolver->currentFixturesDisk();
        $documentsDisk = $this->locationResolver->currentDocumentsDisk();

        $externalId = "fixture:{$fixture->code}";
        $existingDocument = $fixture->document;
        $isNewDocument = $existingDocument === null;

        if ($dryRun) {
            // dry-run не создаёт ничего — возвращаем несохранённые in-memory объекты только для
            // единообразия сигнатуры вызывающего кода (их id/атрибуты дальше не используются).
            return [$existingDocument ?? new Document, new DocumentVersion, $isNewDocument, $isNewDocument];
        }

        $document = $existingDocument ?? $this->documents->create([
            'procurement_id' => null,
            'platform_id' => Platform::training()->id,
            'external_id' => $externalId,
            'title' => $fixture->original_filename,
            'kind' => null,
            'source_url' => null,
            'status' => DocumentStatus::Downloaded,
        ]);

        if ($isNewDocument) {
            $this->fixtures->update($fixture, ['document_id' => $document->id]);
        }

        $existingVersion = $this->documents->findVersionByHash($document->id, $fixture->sha256);
        $isNewVersion = $existingVersion === null;

        if ($existingVersion !== null) {
            return [$document, $existingVersion, $isNewDocument, false];
        }

        $storagePath = ShardedBlobPath::for($fixture->sha256);

        if (! Storage::disk($documentsDisk)->exists($storagePath)) {
            $stream = Storage::disk($fixturesDisk)->readStream($fixture->storage_path);

            if (! is_resource($stream)) {
                throw new \RuntimeException("Fixture source stream is unavailable: {$fixture->storage_path}");
            }

            try {
                Storage::disk($documentsDisk)->put($storagePath, $stream);
            } finally {
                fclose($stream);
            }
        }

        $version = $this->documents->createVersion([
            'document_id' => $document->id,
            'content_hash' => $fixture->sha256,
            'size_bytes' => $fixture->size_bytes,
            'filename' => $fixture->original_filename,
            'extension' => $fixture->source_format,
            'mime' => $fixture->mime,
            'storage_path' => $storagePath,
            'disk' => $documentsDisk,
            'downloaded_at' => now(),
        ]);

        return [$document, $version, $isNewDocument, $isNewVersion];
    }
}
