<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\RepresentationStoreRepository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OCR сканов (P3.7, D22 — Tesseract+OCRmyPDF): PDF без текстового слоя и изображения →
 * ДВА представления, `pdf_ocr` (PDF со встроенным текстовым слоем) и `text` (sidecar).
 * Ценность двойная — вход для будущей ЛОКАЛЬНОЙ модели, которая vision-качества провайдера не
 * даст (D7), и измеримый потолок движка (`research/04-ocr-ceiling.md`): структуру таблицы ни один
 * локальный OCR бесплатно не отдаёт, поэтому здесь производится ТЕКСТ, а не таблица.
 *
 * Порог текстового слоя — канон P1.1 (`0 → none`, `<50 символов на страницу → sparse`, иначе
 * `full`): OCR запускается для `none`/`sparse`, PDF с текстом идёт своим путём (`false` —
 * цепочка продолжается, D28); файл, который зонд не смог прочитать как PDF, тоже уходит дальше
 * по цепочке — см. {@see ScanStrategy::needsOcr()}.
 *
 * Асимметрия исходов при провале OCR — сознательная (D26, «лимит инструмента ≠ приговор
 * документу»):
 * - PDF без текстового слоя: OCR — ЕДИНСТВЕННЫЙ способ получить из него текст, провал терминален
 *   (`unprocessable(ocr_failed)`), как у {@see WordStrategy};
 * - изображение: оригинал сам по себе валидный вход vision-ветки ({@see ImageStrategy}), поэтому
 *   провал OCR — обычный `false`, документ терминальным НЕ становится и уезжает в vision как
 *   раньше. Из-за этого стратегия стоит в реестре ПЕРЕД `ImageStrategy` (и, следовательно, перед
 *   `PassthroughStrategy`, D88).
 */
final readonly class ScanStrategy implements PreparationStrategy
{
    /**
     * Символов текстового слоя на страницу, ниже которых слой считается отсутствующим или
     * мусорным (`sparse`) и документ идёт в OCR — канон инвентаря корпуса P1.1
     * (`artifacts/corpus_inventory.py`, `TEXT_LAYER_SPARSE_MAX`).
     */
    private const float TEXT_LAYER_SPARSE_MAX_CHARS_PER_PAGE = 50.0;

    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'];

    public function __construct(
        private RepresentationStoreRepository $representations,
        private DocumentStoreRepository $storeRepository,
        private OcrConverter $converter,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        return $version->extension === 'pdf' || in_array($version->extension, self::IMAGE_EXTENSIONS, true);
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        if ($this->representations->find($version, RepresentationKind::PdfOcr) !== null
            && $this->representations->find($version, RepresentationKind::Text) !== null) {
            return true;
        }

        $isPdf = $version->extension === 'pdf';
        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $workDir = sys_get_temp_dir().'/ocr_'.Str::uuid();
        $localInput = $workDir.'/source.'.$version->extension;
        $localOutput = $workDir.'/ocr.pdf';
        $localSidecar = $workDir.'/ocr.txt';

        try {
            File::ensureDirectoryExists($workDir);
            // Скан-эталон корпуса весит 132 МБ (`EST-PDF-SCAN-001`) — оригинал и результат OCR
            // ходят между диском и временным файлом ПОТОКОМ, целиком в память не читаются
            // (Code Guidance P3.7); в памяти живёт только sidecar-текст.
            $this->copyToLocal($disk, $version->storage_path, $localInput);

            if ($isPdf && ! $this->needsOcr($localInput)) {
                return false;
            }

            // Ловится ТОЛЬКО провал самого OCR — доменный исход стратегии; сбой доступа к диску
            // выше по методу пробрасывается в error-блок job'а и под «не подошла» не маскируется (D28).
            try {
                $startedAt = microtime(as_float: true);
                $this->converter->run($localInput, $localOutput, $localSidecar);
                $durationMs = (int) round((microtime(as_float: true) - $startedAt) * 1000);

                $text = file_exists($localSidecar) ? (string) file_get_contents($localSidecar) : '';

                if (trim($text) === '') {
                    throw new RuntimeException('OCR не извлёк ни одного символа (пустой sidecar)');
                }
            } catch (RuntimeException $e) {
                if (! $isPdf) {
                    return false;
                }

                $this->markUnprocessable($document, $e->getMessage());

                return true;
            }

            $this->storeRepresentations($version, $disk, $localOutput, $text, $durationMs);

            return true;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function storeRepresentations(
        DocumentVersion $version,
        Filesystem $disk,
        string $localOutput,
        string $text,
        int $durationMs,
    ): void {
        $meta = [
            'tool' => 'ocrmypdf',
            'tesseract_version' => $this->converter->tesseractVersion(),
            'languages' => $this->converter->languages(),
            'duration_ms' => $durationMs,
        ];

        $pdfPath = $this->preparedPath($version, 'ocr.pdf');
        $pdfStream = fopen($localOutput, 'r');
        $disk->put($pdfPath, $pdfStream);
        if (is_resource($pdfStream)) {
            fclose($pdfStream);
        }

        $this->representations->storeOrUpdate($version, RepresentationKind::PdfOcr, [
            'storage_path' => $pdfPath,
            'disk' => null,
            'size_bytes' => (int) filesize($localOutput),
            'page_count' => $this->converter->pageCount($localOutput),
            'mime' => 'application/pdf',
            'meta' => $meta,
            'generated_at' => now(),
        ]);

        $textPath = $this->preparedPath($version, 'ocr.txt');
        $disk->put($textPath, $text);

        $this->representations->storeOrUpdate($version, RepresentationKind::Text, [
            'storage_path' => $textPath,
            'disk' => null,
            'size_bytes' => strlen($text),
            // Та же эвристика оценки токенов, что у `text_grid` (P3.5): 4 символа на токен.
            'token_estimate' => (int) ceil(strlen($text) / 4),
            'mime' => 'text/plain',
            'meta' => $meta,
            'generated_at' => now(),
        ]);
    }

    /**
     * OCR нужен только PDF с отсутствующим или мусорным текстовым слоем (`none`/`sparse`).
     * Зонд, не сумевший прочитать файл как PDF (`null` — битый xref, подменённое расширение),
     * НЕ считается «текста нет»: такой документ идёт дальше по цепочке ровно как до P3.7
     * (`PassthroughStrategy` отдаёт оригинал), а не тратит минуты OCR на заведомый мусор.
     */
    private function needsOcr(string $localPdf): bool
    {
        $charsPerPage = $this->converter->textCharsPerPage($localPdf);

        return $charsPerPage !== null && $charsPerPage < self::TEXT_LAYER_SPARSE_MAX_CHARS_PER_PAGE;
    }

    private function copyToLocal(Filesystem $disk, string $storagePath, string $localPath): void
    {
        $source = $disk->readStream($storagePath);

        if (! is_resource($source)) {
            throw new RuntimeException("Оригинал недоступен на диске: {$storagePath}");
        }

        try {
            $target = fopen($localPath, 'w');

            if (! is_resource($target)) {
                throw new RuntimeException("Не удалось открыть временный файл: {$localPath}");
            }

            stream_copy_to_stream($source, $target);
            fclose($target);
        } finally {
            fclose($source);
        }
    }

    private function preparedPath(DocumentVersion $version, string $suffix): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.{$suffix}";
    }

    private function markUnprocessable(Document $document, string $detail): void
    {
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.UnprocessableReason::OcrFailed->value.": {$detail}",
        ]);
    }
}
