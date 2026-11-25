<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * `doc`/`docx`/`rtf`/`odt` → PDF через LibreOffice. Конверсия — единственный путь:
 * её отказ терминален (`unprocessable(conversion_failed)`), цепочка на этом заканчивается.
 * Исходный офисный документ в LLM не отправляется.
 */
final readonly class WordStrategy implements PreparationStrategy
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private RepresentationWriter $writer,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private LibreOfficeConverter $converter,
        private FileLocationResolver $locationResolver,
        private XlsxResourceGuard $xlsxResourceGuard,
    ) {}

    public function process(Document $document, DocumentVersion $version, ?float $deadlineAt = null): PreparationResult
    {
        if ($this->representations->find($version, RepresentationKind::Pdf) !== null) {
            return PreparationResult::ReadyForScreening;
        }

        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $sourceDiskName = $version->disk !== '' ? $version->disk : $diskName;
        $sourceDisk = $sourceDiskName === $diskName ? $disk : Storage::disk($sourceDiskName);
        $localInput = PipelineTemporaryFile::create(prefix: 'word_', extension: $version->extension);
        $outputDir = PipelineTemporaryFile::createDirectory(prefix: 'word_pdf_');

        try {
            file_put_contents($localInput, $sourceDisk->get($version->storage_path));

            if (in_array($version->extension, ['docx', 'odt'], true)) {
                try {
                    $this->xlsxResourceGuard->assertSafeXmlEntries($localInput);
                } catch (RuntimeException $exception) {
                    $this->markUnprocessable->execute(
                        document: $document,
                        reason: UnprocessableReason::UnreadableFormat,
                        detail: $exception->getMessage(),
                    );

                    return PreparationResult::Unprocessable;
                }
            }

            try {
                $localOutput = $this->converter->toPdf(inputPath: $localInput, outputDir: $outputDir, deadlineAt: $deadlineAt);
            } catch (OperationBudgetExceeded|ToolCleanupUnconfirmed $exception) {
                throw $exception;
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
                    'tool_version' => $this->converter->version(deadlineAt: $deadlineAt),
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
