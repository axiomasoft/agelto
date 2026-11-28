<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Strategies;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Procurement\Prepare\Archive\ArchiveWorkspaceHelper;
use App\Services\Procurement\Prepare\RepresentationWriter;
use App\Services\Procurement\Prepare\Tools\LibreOfficeConverter;
use App\Services\Procurement\Prepare\Tools\PreparedRepresentationHelper;
use App\Services\Procurement\Prepare\Tools\XlsxResourceGuard;
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
            ArchiveWorkspaceHelper::copyToLocal(
                disk: $sourceDisk,
                storagePath: $version->storage_path,
                localPath: $localInput,
                deadlineAt: $deadlineAt,
            );

            if (in_array($version->extension, ['docx', 'odt'], true)) {
                try {
                    $this->xlsxResourceGuard->assertSafeXmlEntries($localInput);
                } catch (RuntimeException $exception) {
                    PreparedRepresentationHelper::markUnprocessable(
                        $this->markUnprocessable,
                        $document,
                        UnprocessableReason::UnreadableFormat,
                        $exception->getMessage(),
                    );

                    return PreparationResult::Unprocessable;
                }
            }

            try {
                $localOutput = $this->converter->toPdf(inputPath: $localInput, outputDir: $outputDir, deadlineAt: $deadlineAt);
            } catch (OperationBudgetExceeded|ToolCleanupUnconfirmed $exception) {
                throw $exception;
            } catch (RuntimeException $e) {
                PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::ConversionFailed, $e->getMessage());

                return PreparationResult::Unprocessable;
            }

            if (! $this->acceptsPdfFile($localOutput)) {
                PreparedRepresentationHelper::markUnprocessable(
                    $this->markUnprocessable,
                    $document,
                    UnprocessableReason::ConversionFailed,
                    'LibreOffice создал пустой или некорректный PDF',
                );

                return PreparationResult::Unprocessable;
            }

            $preparedPath = PreparedRepresentationHelper::preparedPath($version, 'pdf');
            $pdfStream = fopen($localOutput, 'rb');

            if (! is_resource($pdfStream)) {
                throw new RuntimeException("Не удалось открыть PDF после конвертации: {$localOutput}");
            }

            try {
                $this->writer->writeStream(
                    disk: $disk,
                    version: $version,
                    kind: RepresentationKind::Pdf,
                    path: $preparedPath,
                    stream: $pdfStream,
                    attributes: [
                        'storage_path' => $preparedPath,
                        'disk' => $diskName,
                        'size_bytes' => (int) filesize($localOutput),
                        'mime' => 'application/pdf',
                        'meta' => [
                            'tool' => 'libreoffice',
                            'tool_version' => $this->converter->version(deadlineAt: $deadlineAt),
                        ],
                        'generated_at' => now(),
                    ],
                );
            } finally {
                fclose($pdfStream);
            }

            return PreparationResult::ReadyForScreening;
        } finally {
            @unlink($localInput);
            PipelineTemporaryFile::deleteDirectory($outputDir);
        }
    }

    private function acceptsPdfFile(string $path): bool
    {
        if (is_link($path) || ! is_file($path)) {
            return false;
        }

        $size = @filesize($path);

        if (! is_int($size) || $size <= 0) {
            return false;
        }

        return @file_get_contents($path, false, null, 0, 4) === '%PDF';
    }
}
