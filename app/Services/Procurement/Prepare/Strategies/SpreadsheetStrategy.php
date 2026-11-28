<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Strategies;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Console\Commands\Procurement\ConvertXlsxToPdfCommand;
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
use App\Services\Procurement\Prepare\Tools\TextGridRenderer;
use App\Services\Procurement\Prepare\Tools\XlsxResourceGuard;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Composer\InstalledVersions;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Excel → PDF (landscape + fitToWidth(1)/fitToHeight(0), v1-урок против обрезки по столбцам,
 * `Old/.../Strategies/ExcelStrategy.php`) — xlsx size-gate:
 * размер проверяется в воркере, а метаданные строк — внутри отдельного процесса
 * до полной загрузки книги. Конверсия выполняется в ИЗОЛИРОВАННОМ
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

    public function process(Document $document, DocumentVersion $version, ?float $deadlineAt = null): PreparationResult
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
            ArchiveWorkspaceHelper::copyToLocal(
                disk: $sourceDisk,
                storagePath: $version->storage_path,
                localPath: $localInput,
                deadlineAt: $deadlineAt,
            );

            if ($version->extension === 'xlsx') {
                try {
                    $this->xlsxResourceGuard->assertSafeXmlEntries($localInput);
                } catch (RuntimeException $exception) {
                    PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::UnreadableFormat, $exception->getMessage());

                    return PreparationResult::Unprocessable;
                }
            }

            $withinToolLimits = $this->withinToolLimits($version, $localInput);

            if (! $hasPdf) {
                $hasPdf = ($withinToolLimits && $this->tryPhpSpreadsheet($version, $disk, $diskName, $localInput, $deadlineAt))
                    || $this->tryLibreOffice($version, $disk, $diskName, $localInput, $deadlineAt);
            }

            if (! $hasTextGrid && $withinToolLimits) {
                $this->storeTextGrid($version, $disk, $diskName, $localInput, $deadlineAt);
            }

            if (! $hasPdf) {
                PreparedRepresentationHelper::markUnprocessable(
                    $this->markUnprocessable,
                    $document,
                    UnprocessableReason::ConversionFailed,
                    'PhpSpreadsheet и LibreOffice не смогли сконвертировать файл в PDF',
                );
            }

            return $hasPdf ? PreparationResult::ReadyForScreening : PreparationResult::Unprocessable;
        } finally {
            @unlink($localInput);
        }
    }

    private function withinToolLimits(DocumentVersion $version, string $localInput): bool
    {
        if (! $this->xlsxResourceGuard->accepts(path: $localInput, extension: $version->extension, checkXmlEntities: false)) {
            return false;
        }

        $maxMb = $this->floatConfig(key: 'ai.prepare.xlsx_max_mb', default: 25);
        $actualBytes = filesize($localInput);

        return $actualBytes !== false && max($version->size_bytes, $actualBytes) <= $maxMb * 1024 * 1024;
    }

    private function tryPhpSpreadsheet(DocumentVersion $version, Filesystem $disk, string $diskName, string $localInput, ?float $deadlineAt): bool
    {
        $localOutput = PipelineTemporaryFile::create(prefix: 'xlsx_pdf_', extension: 'pdf');

        try {
            $startedAt = microtime(as_float: true);

            try {
                $this->convert(input: $localInput, output: $localOutput, deadlineAt: $deadlineAt);
            } catch (OperationBudgetExceeded $exception) {
                throw $exception;
            } catch (RuntimeException) {
                return false;
            }

            $durationMs = (int) round((microtime(as_float: true) - $startedAt) * 1000);

            if (! $this->acceptsPdfFile($localOutput)) {
                return false;
            }

            $this->storePdfRepresentation($version, $disk, $diskName, $localOutput, [
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

    private function tryLibreOffice(DocumentVersion $version, Filesystem $disk, string $diskName, string $localInput, ?float $deadlineAt): bool
    {
        $outputDir = PipelineTemporaryFile::createDirectory(prefix: 'xlsx_pdf_');

        try {
            try {
                $localOutput = $this->converter->toPdf(inputPath: $localInput, outputDir: $outputDir, deadlineAt: $deadlineAt);
            } catch (OperationBudgetExceeded|ToolCleanupUnconfirmed $exception) {
                throw $exception;
            } catch (RuntimeException) {
                return false;
            }

            if (! $this->acceptsPdfFile($localOutput)) {
                return false;
            }

            $this->storePdfRepresentation($version, $disk, $diskName, $localOutput, [
                'tool' => 'libreoffice',
                'tool_version' => $this->converter->version(deadlineAt: $deadlineAt),
            ]);

            return true;
        } finally {
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

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storePdfRepresentation(DocumentVersion $version, Filesystem $disk, string $diskName, string $localOutput, array $meta): void
    {
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
                    'meta' => $meta,
                    'generated_at' => now(),
                ],
            );
        } finally {
            fclose($pdfStream);
        }
    }

    private function storeTextGrid(DocumentVersion $version, Filesystem $disk, string $diskName, string $localInput, ?float $deadlineAt): void
    {
        $rendered = $this->textGridRenderer->render(localPath: $localInput, extension: $version->extension, deadlineAt: $deadlineAt);

        if ($rendered === false) {
            return;
        }

        $preparedPath = PreparedRepresentationHelper::preparedPath($version, 'text_grid.txt');
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

    private function convert(string $input, string $output, ?float $deadlineAt): void
    {
        $memoryLimit = $this->stringConfig(key: 'ai.prepare.xlsx_convert_memory_limit', default: '512M');
        $timeout = $this->integerConfig(key: 'ai.prepare.xlsx_convert_timeout_seconds', default: 120);
        $maxRows = $this->integerConfig(key: 'ai.prepare.xlsx_max_rows', default: 20_000);
        $remaining = $deadlineAt === null ? $timeout : (int) floor($deadlineAt - microtime(true));
        if ($remaining < 1) {
            throw OperationBudgetExceeded::forOperation(operation: 'конвертации Excel', availableSeconds: $remaining, requiredSeconds: 1);
        }

        $result = Process::path(base_path())
            ->timeout(min($timeout, $remaining))
            ->quietly()
            ->run([PHP_BINARY, '-d', "memory_limit={$memoryLimit}", 'artisan', 'procurement:convert-xlsx-to-pdf', $input, $output, "--max-rows={$maxRows}"]);

        if ($result->failed() || ! file_exists($output)) {
            throw new RuntimeException('Конвертация Excel→PDF провалилась.');
        }
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

        if (! is_string($value) || preg_match('/^[1-9][0-9]*[KMG]?$/iD', $value) !== 1 || ini_parse_quantity($value) < 1) {
            throw new RuntimeException("{$key} must be a positive PHP memory limit.");
        }

        return $value;
    }
}
