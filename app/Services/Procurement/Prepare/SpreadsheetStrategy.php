<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Console\Commands\Procurement\ConvertXlsxToPdfCommand;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Composer\InstalledVersions;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use RuntimeException;

/**
 * Excel → PDF (landscape + fitToWidth(1)/fitToHeight(0), v1-урок против обрезки по столбцам,
 * `Old/.../Strategies/ExcelStrategy.php`) — xlsx size-gate:
 * строки/размер проверяются ДЁШЕВО (`size_bytes` из БД + `listWorksheetInfo` — метаданные
 * листа без загрузки данных в память) ДО полной загрузки. Сама конверсия — в ИЗОЛИРОВАННОМ
 * PHP-процессе с явным `memory_limit` ({@see ConvertXlsxToPdfCommand}), чтобы OOM не убивал
 * соседние jobs воркера.
 *
 * Превышение любого из порогов `config('ai.prepare.xlsx_*')` ЛИБО падение самой конверсии —
 * лимит ИНСТРУМЕНТА, а не приговор документу: вторая ветка пробует
 * `LibreOfficeConverter` (fidelity-конвертер, сохраняет print-setup и merged cells). Обе
 * ветки пишут ОДИН kind `pdf` (различаются `meta.tool`) — выбор представления для LLM
 * задаётся общей политикой; отдельный kind для каждой ветки не создаётся. Обе ветки упали →
 * `unprocessable(conversion_failed)`.
 *
 * Второй проход того же вызова пишет ВТОРОЕ представление `text_grid`
 * ({@see TextGridRenderer}) — третий кандидат входа в LLM наряду с PDF и OCR-текстом; тот же
 * size-gate, что у PDF-ветки, но БЕЗ фолбэка (у текстовой сетки нет второго инструмента):
 * превышение лимита просто не производит `text_grid`, документ при этом НЕ становится
 * unprocessable, если PDF получился хотя бы одной из веток.
 */
final readonly class SpreadsheetStrategy implements PreparationStrategy
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private RepresentationWriter $writer,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private LibreOfficeConverter $converter,
        private TextGridRenderer $textGridRenderer,
        private XlsxResourceGuard $xlsxResourceGuard,
        private FileLocationResolver $locationResolver,
    ) {}

    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        $hasPdf = $this->representations->find($version, RepresentationKind::Pdf) !== null;
        $hasTextGrid = $this->representations->find($version, RepresentationKind::TextGrid) !== null;

        if ($hasPdf && $hasTextGrid) {
            return PreparationResult::ReadyForScreening;
        }

        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $sourceDiskName = $version->disk !== '' ? $version->disk : $diskName;
        $sourceDisk = $sourceDiskName === $diskName ? $disk : Storage::disk($sourceDiskName);
        $localInput = PipelineTemporaryFile::create(prefix: 'xlsx_', extension: $version->extension);

        try {
            file_put_contents($localInput, $sourceDisk->get($version->storage_path));

            $withinToolLimits = $this->withinToolLimits($version, $localInput);

            if (! $hasPdf) {
                $hasPdf = ($withinToolLimits && $this->tryPhpSpreadsheet($version, $disk, $diskName, $localInput))
                    || $this->tryLibreOffice($version, $disk, $diskName, $localInput);
            }

            if (! $hasTextGrid && $withinToolLimits) {
                $this->storeTextGrid($version, $disk, $diskName, $localInput);
            }

            if (! $hasPdf) {
                $this->markUnprocessable($document, 'PhpSpreadsheet и LibreOffice не смогли сконвертировать файл в PDF');
            }

            return $hasPdf ? PreparationResult::ReadyForScreening : PreparationResult::Unprocessable;
        } finally {
            @unlink($localInput);
        }
    }

    private function withinToolLimits(DocumentVersion $version, string $localInput): bool
    {
        if (! $this->xlsxResourceGuard->accepts(path: $localInput, extension: $version->extension)) {
            return false;
        }

        $maxMb = $this->floatConfig(key: 'ai.prepare.xlsx_max_mb', default: 25);
        if ($version->size_bytes > $maxMb * 1024 * 1024) {
            return false;
        }

        $maxRows = $this->integerConfig(key: 'ai.prepare.xlsx_max_rows', default: 20000);

        return $this->countRows($localInput, $version->extension) <= $maxRows;
    }

    private function tryPhpSpreadsheet(DocumentVersion $version, Filesystem $disk, string $diskName, string $localInput): bool
    {
        $localOutput = PipelineTemporaryFile::create(prefix: 'xlsx_pdf_', extension: 'pdf');

        try {
            $startedAt = microtime(as_float: true);

            try {
                $this->convert($localInput, $localOutput);
            } catch (RuntimeException) {
                return false;
            }

            $durationMs = (int) round((microtime(as_float: true) - $startedAt) * 1000);

            $pdf = (string) file_get_contents($localOutput);
            $this->storePdfRepresentation($version, $disk, $diskName, $pdf, [
                'tool' => 'phpspreadsheet+mpdf',
                'tool_version' => InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet'),
                'duration_ms' => $durationMs,
                'source_kind' => $version->extension,
            ]);

            return true;
        } finally {
            @unlink($localOutput);
        }
    }

    private function tryLibreOffice(DocumentVersion $version, Filesystem $disk, string $diskName, string $localInput): bool
    {
        $outputDir = PipelineTemporaryFile::createDirectory(prefix: 'xlsx_pdf_');

        try {
            try {
                $localOutput = $this->converter->toPdf($localInput, $outputDir);
            } catch (RuntimeException) {
                return false;
            }

            $pdf = (string) file_get_contents($localOutput);
            $this->storePdfRepresentation($version, $disk, $diskName, $pdf, [
                'tool' => 'libreoffice',
                'tool_version' => $this->converter->version(),
            ]);

            return true;
        } finally {
            PipelineTemporaryFile::deleteDirectory($outputDir);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storePdfRepresentation(DocumentVersion $version, Filesystem $disk, string $diskName, string $pdf, array $meta): void
    {
        $preparedPath = $this->preparedPath($version);
        $this->writer->write($disk, $version, RepresentationKind::Pdf, $preparedPath, $pdf, [
            'storage_path' => $preparedPath,
            'disk' => $diskName,
            'size_bytes' => strlen($pdf),
            'mime' => 'application/pdf',
            'meta' => $meta,
            'generated_at' => now(),
        ]);
    }

    private function storeTextGrid(DocumentVersion $version, Filesystem $disk, string $diskName, string $localInput): void
    {
        $rendered = $this->textGridRenderer->render($localInput, $version->extension);

        if ($rendered === false) {
            return;
        }

        $preparedPath = $this->preparedTextGridPath($version);
        $this->writer->write($disk, $version, RepresentationKind::TextGrid, $preparedPath, $rendered['text'], [
            'storage_path' => $preparedPath,
            'disk' => $diskName,
            'size_bytes' => strlen($rendered['text']),
            'token_estimate' => (int) ceil(strlen($rendered['text']) / 4),
            'mime' => 'text/tab-separated-values',
            'meta' => [
                'tool' => 'phpspreadsheet',
                'sheets' => $rendered['sheets'],
                'rows' => $rendered['rows'],
            ],
            'generated_at' => now(),
        ]);
    }

    private function countRows(string $path, string $extension): int
    {
        $reader = $extension === 'xls' ? new XlsReader : new XlsxReader;
        $info = $reader->listWorksheetInfo($path);

        return array_sum(array_column($info, 'totalRows'));
    }

    private function convert(string $input, string $output): void
    {
        $memoryLimit = $this->stringConfig(key: 'ai.prepare.xlsx_convert_memory_limit', default: '512M');
        $timeout = $this->integerConfig(key: 'ai.prepare.xlsx_convert_timeout_seconds', default: 120);

        $result = Process::path(base_path())
            ->timeout($timeout)
            ->run([PHP_BINARY, '-d', "memory_limit={$memoryLimit}", 'artisan', 'procurement:convert-xlsx-to-pdf', $input, $output]);

        if ($result->failed() || ! file_exists($output)) {
            throw new RuntimeException('Конвертация Excel→PDF провалилась: '.$result->errorOutput());
        }
    }

    private function preparedPath(DocumentVersion $version): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.pdf";
    }

    private function preparedTextGridPath(DocumentVersion $version): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.text_grid.txt";
    }

    private function markUnprocessable(Document $document, string $detail): void
    {
        $this->markUnprocessable->execute($document, UnprocessableReason::ConversionFailed, $detail);
    }

    private function floatConfig(string $key, float|int $default): float
    {
        $value = config($key, $default);

        if (! is_float($value) && ! is_int($value)) {
            throw new RuntimeException("{$key} must be a number.");
        }

        return (float) $value;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new RuntimeException("{$key} must be an integer.");
        }

        return $value;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        if (! is_string($value)) {
            throw new RuntimeException("{$key} must be a string.");
        }

        return $value;
    }
}
