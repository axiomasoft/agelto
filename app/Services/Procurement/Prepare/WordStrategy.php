<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * `doc`/`docx`/`rtf`/`odt` → PDF через LibreOffice (P3.4, D7). До этого item'а такие версии
 * падали в `PassthroughStrategy` и уходили в LLM как оригинал (RAG:—,
 * `findings/corpus-recon-2026-07-26.md` §1–§2). Конверсия здесь — единственный путь: упавшая
 * конверсия терминальна САМА ПО СЕБЕ (`unprocessable(conversion_failed)`), цепочка на этом
 * заканчивается — оригинал в LLM больше не уходит.
 */
final readonly class WordStrategy implements PreparationStrategy
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private RepresentationWriter $writer,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private LibreOfficeConverter $converter,
        private FileLocationResolver $locationResolver,
    ) {}

    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        if ($this->representations->find($version, RepresentationKind::Pdf) !== null) {
            return PreparationResult::ReadyForScreening;
        }

        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $localInput = PipelineTemporaryFile::create(prefix: 'word_', extension: $version->extension);
        $outputDir = PipelineTemporaryFile::createDirectory(prefix: 'word_pdf_');

        try {
            file_put_contents($localInput, $disk->get($version->storage_path));

            try {
                $localOutput = $this->converter->toPdf($localInput, $outputDir);
            } catch (RuntimeException $e) {
                $this->markUnprocessable($document, $e->getMessage());

                return PreparationResult::Unprocessable;
            }

            $pdf = (string) file_get_contents($localOutput);
            $preparedPath = $this->preparedPath($version);
            $this->writer->write($disk, $version, RepresentationKind::Pdf, $preparedPath, $pdf, [
                'storage_path' => $preparedPath,
                'disk' => $diskName,
                'size_bytes' => strlen($pdf),
                'mime' => 'application/pdf',
                'meta' => [
                    'tool' => 'libreoffice',
                    'tool_version' => $this->converter->version(),
                ],
                'generated_at' => now(),
            ]);

            return PreparationResult::ReadyForScreening;
        } finally {
            @unlink($localInput);
            PipelineTemporaryFile::deleteDirectory($outputDir);
        }
    }

    private function preparedPath(DocumentVersion $version): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.pdf";
    }

    private function markUnprocessable(Document $document, string $detail): void
    {
        $this->markUnprocessable->execute($document, UnprocessableReason::ConversionFailed, $detail);
    }
}
