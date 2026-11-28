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
use App\Services\Procurement\Prepare\Tools\OcrConverter;
use App\Services\Procurement\Prepare\Tools\PreparedRepresentationHelper;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR сканов ( Tesseract+OCRmyPDF): PDF без текстового слоя и изображения →
 * ДВА представления, `pdf_ocr` (PDF со встроенным текстовым слоем) и `text` (sidecar).
 * Ценность двойная — вход для будущей ЛОКАЛЬНОЙ модели, которая vision-качества провайдера не
 * даст, и измеримый потолок движка (`research/04-ocr-ceiling.md`): структуру таблицы ни один
 * локальный OCR бесплатно не отдаёт, поэтому здесь производится ТЕКСТ, а не таблица.
 *
 * Порог текстового слоя — правило классификации (`0 → none`, `<50 символов на страницу → sparse`, иначе
 * `full`): OCR запускается для `none`/`sparse`, PDF с текстом идёт своим путём (`false` —
 * цепочка продолжается); файл, который зонд не смог прочитать как PDF, тоже уходит дальше
 * по цепочке — см. {@see ScanStrategy::needsOcr}.
 *
 * Асимметрия исходов при провале OCR — сознательная («лимит инструмента ≠ приговор
 * документу»):
 * - PDF без текстового слоя: OCR — ЕДИНСТВЕННЫЙ способ получить из него текст, провал терминален
 *   (`unprocessable(ocr_failed)`), как у {@see WordStrategy};
 * - изображение: оригинал сам по себе валидный вход vision-ветки ({@see ImageStrategy}), поэтому
 *   провал OCR — обычный `false`, документ терминальным НЕ становится и уезжает в vision как
 *   раньше. Из-за этого стратегия стоит в реестре ПЕРЕД `ImageStrategy` (и, следовательно, перед
 *   `PassthroughStrategy`).
 */
final readonly class ScanStrategy implements PreparationStrategy
{
    /**
     * Символов текстового слоя на страницу, ниже которых слой считается отсутствующим или
     * мусорным (`sparse`), и документ идёт в OCR.
     * (`artifacts/corpus_inventory.py`, `TEXT_LAYER_SPARSE_MAX`).
     */
    private const float TEXT_LAYER_SPARSE_MAX_CHARS_PER_PAGE = 50.0;

    public function __construct(
        private RepresentationStoreRepository $representations,
        private RepresentationWriter $writer,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private OcrConverter $converter,
        private FileLocationResolver $locationResolver,
    ) {}

    public function process(Document $document, DocumentVersion $version, ?float $deadlineAt = null): PreparationResult
    {
        if ($this->representations->find($version, RepresentationKind::PdfOcr) !== null
            && $this->representations->find($version, RepresentationKind::Text) !== null) {
            return PreparationResult::ReadyForScreening;
        }

        $isPdf = $version->extension === 'pdf';
        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $sourceDiskName = $version->disk !== '' ? $version->disk : $diskName;
        $sourceDisk = $sourceDiskName === $diskName ? $disk : Storage::disk($sourceDiskName);
        $workDir = PipelineTemporaryFile::createDirectory(prefix: 'ocr_');
        $localInput = $workDir.'/source.'.$version->extension;
        $localOutput = $workDir.'/ocr.pdf';
        $localSidecar = $workDir.'/ocr.txt';

        try {
            // Скан-эталон корпуса весит 132 МБ (`EST-PDF-SCAN-001`) — оригинал и результат OCR
            // ходят между диском и временным файлом ПОТОКОМ, целиком в память не читаются
            // В памяти живёт только sidecar-текст.
            ArchiveWorkspaceHelper::copyToLocal($sourceDisk, $version->storage_path, $localInput);

            if ($isPdf && ! $this->needsOcr(localPdf: $localInput, deadlineAt: $deadlineAt)) {
                return PreparationResult::ReadyForScreening;
            }

            // Ловится ТОЛЬКО провал самого OCR — доменный исход стратегии; сбой доступа к диску
            // выше по методу пробрасывается в error-блок job'а и под «не подошла» не маскируется.
            try {
                $startedAt = microtime(as_float: true);
                $this->converter->run(
                    inputPath: $localInput,
                    outputPdfPath: $localOutput,
                    sidecarTxtPath: $localSidecar,
                    deadlineAt: $deadlineAt,
                );
                $durationMs = (int) round((microtime(as_float: true) - $startedAt) * 1000);

                $text = file_exists($localSidecar) ? (string) file_get_contents($localSidecar) : '';

                if (trim($text) === '') {
                    throw new RuntimeException('OCR не извлёк ни одного символа (пустой sidecar)');
                }
            } catch (OperationBudgetExceeded|ToolCleanupUnconfirmed $exception) {
                // A job-slice deadline is a transient scheduling outcome. It must enter the
                // PipelineJob error block and retry; OCR itself has not proved the PDF invalid.
                throw $exception;
            } catch (RuntimeException $e) {
                if (! $isPdf) {
                    return PreparationResult::ReadyForScreening;
                }

                PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::OcrFailed, $e->getMessage());

                return PreparationResult::Unprocessable;
            }

            $this->storeRepresentations(
                version: $version,
                disk: $disk,
                diskName: $diskName,
                localOutput: $localOutput,
                text: $text,
                durationMs: $durationMs,
                deadlineAt: $deadlineAt,
            );

            return PreparationResult::ReadyForScreening;
        } finally {
            PipelineTemporaryFile::deleteDirectory($workDir);
        }
    }

    private function storeRepresentations(
        DocumentVersion $version,
        Filesystem $disk,
        string $diskName,
        string $localOutput,
        string $text,
        int $durationMs,
        ?float $deadlineAt,
    ): void {
        $meta = [
            'tool' => 'ocrmypdf',
            'tesseract_version' => $this->converter->tesseractVersion(deadlineAt: $deadlineAt),
            'languages' => $this->converter->languages(),
            'duration_ms' => $durationMs,
        ];

        $pdfPath = PreparedRepresentationHelper::preparedPath($version, 'ocr.pdf');
        $pdfStream = fopen($localOutput, 'r');

        if (! is_resource($pdfStream)) {
            throw new RuntimeException("Не удалось открыть OCR PDF: {$localOutput}");
        }

        try {
            $this->writer->writeStream(disk: $disk, version: $version, kind: RepresentationKind::PdfOcr, path: $pdfPath, stream: $pdfStream, attributes: [
                'storage_path' => $pdfPath,
                'disk' => $diskName,
                'size_bytes' => (int) filesize($localOutput),
                'page_count' => $this->converter->pageCount(pdfPath: $localOutput, deadlineAt: $deadlineAt),
                'mime' => 'application/pdf',
                'meta' => $meta,
                'generated_at' => now(),
            ]);
        } finally {
            fclose($pdfStream);
        }

        $textPath = PreparedRepresentationHelper::preparedPath($version, 'ocr.txt');
        $this->writer->write($disk, $version, RepresentationKind::Text, $textPath, $text, [
            'storage_path' => $textPath,
            'disk' => $diskName,
            'size_bytes' => strlen($text),
            // Та же эвристика оценки токенов, что у `text_grid`: 4 символа на токен.
            'token_estimate' => (int) ceil(strlen($text) / 4),
            'mime' => 'text/plain',
            'meta' => $meta,
            'generated_at' => now(),
        ]);
    }

    /**
     * OCR нужен только PDF с отсутствующим или мусорным текстовым слоем (`none`/`sparse`).
     * Зонд, не сумевший прочитать файл как PDF (`null` — битый xref, подменённое расширение),
     * НЕ считается «текста нет»: такой документ идёт дальше по цепочке
     * (`PassthroughStrategy` отдаёт оригинал), а не тратит минуты OCR на заведомый мусор.
     */
    private function needsOcr(string $localPdf, ?float $deadlineAt): bool
    {
        $charsPerPage = $this->converter->textCharsPerPage(pdfPath: $localPdf, deadlineAt: $deadlineAt);

        return $charsPerPage !== null && $charsPerPage < self::TEXT_LAYER_SPARSE_MAX_CHARS_PER_PAGE;
    }
}
