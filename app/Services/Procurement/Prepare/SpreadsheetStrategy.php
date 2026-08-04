<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Console\Commands\Procurement\ConvertXlsxToPdfCommand;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use Composer\InstalledVersions;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use RuntimeException;

/**
 * Excel → PDF (landscape + fitToWidth(1)/fitToHeight(0), v1-урок против обрезки по столбцам,
 * `Old/.../Strategies/ExcelStrategy.php`) — xlsx size-gate (D35, research/L finding 5):
 * строки/размер проверяются ДЁШЕВО (`size_bytes` из БД + `listWorksheetInfo()` — метаданные
 * листа без загрузки данных в память) ДО полной загрузки. Сама конверсия — в ИЗОЛИРОВАННОМ
 * PHP-процессе с явным `memory_limit` ({@see ConvertXlsxToPdfCommand}), чтобы OOM не убивал
 * соседние jobs воркера.
 *
 * Превышение любого из порогов `config('ai.prepare.xlsx_*')` ЛИБО падение самой конверсии —
 * лимит ИНСТРУМЕНТА, а не приговор документу (D26/D28, P3.4): вторая ветка пробует
 * `LibreOfficeConverter` (fidelity-конвертер, сохраняет print-setup и merged cells, D7). Обе
 * ветки пишут ОДИН kind `pdf` (различаются `meta.tool`) — выбор представления для LLM
 * принадлежит P6 (D3), заводить второй kind здесь запрещено. Обе ветки упали →
 * `unprocessable(conversion_failed)`.
 *
 * Второй проход того же вызова (P3.5, D3) пишет ВТОРОЕ представление `text_grid`
 * ({@see TextGridRenderer}) — третий кандидат входа в LLM наряду с PDF и OCR-текстом; тот же
 * size-gate, что у PDF-ветки, но БЕЗ фолбэка (у текстовой сетки нет второго инструмента):
 * превышение лимита просто не производит `text_grid`, документ при этом НЕ становится
 * unprocessable, если PDF получился хотя бы одной из веток.
 */
final readonly class SpreadsheetStrategy implements PreparationStrategy
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private DocumentStoreRepository $storeRepository,
        private LibreOfficeConverter $converter,
        private TextGridRenderer $textGridRenderer,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        return in_array($version->extension, ['xls', 'xlsx'], true);
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        $hasPdf = $this->representations->find($version, RepresentationKind::Pdf) !== null;
        $hasTextGrid = $this->representations->find($version, RepresentationKind::TextGrid) !== null;

        if ($hasPdf && $hasTextGrid) {
            return true;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localInput = tempnam(sys_get_temp_dir(), 'xlsx_').'.'.$version->extension;

        try {
            file_put_contents($localInput, $disk->get($version->storage_path));

            $withinToolLimits = $this->withinToolLimits($version, $localInput);

            if (! $hasPdf) {
                $hasPdf = ($withinToolLimits && $this->tryPhpSpreadsheet($version, $disk, $localInput))
                    || $this->tryLibreOffice($version, $disk, $localInput);
            }

            if (! $hasTextGrid) {
                $this->storeTextGrid($version, $disk, $localInput);
            }

            if (! $hasPdf) {
                $this->markUnprocessable($document, 'PhpSpreadsheet и LibreOffice не смогли сконвертировать файл в PDF');
            }

            return true;
        } finally {
            @unlink($localInput);
        }
    }

    private function withinToolLimits(DocumentVersion $version, string $localInput): bool
    {
        $maxMb = (float) config('ai.prepare.xlsx_max_mb', 25);
        if ($version->size_bytes > $maxMb * 1024 * 1024) {
            return false;
        }

        $maxRows = (int) config('ai.prepare.xlsx_max_rows', 20000);

        return $this->countRows($localInput, $version->extension) <= $maxRows;
    }

    private function tryPhpSpreadsheet(DocumentVersion $version, Filesystem $disk, string $localInput): bool
    {
        $localOutput = tempnam(sys_get_temp_dir(), 'xlsx_pdf_').'.pdf';

        try {
            $startedAt = microtime(as_float: true);
            $this->convert($localInput, $localOutput);
            $durationMs = (int) round((microtime(as_float: true) - $startedAt) * 1000);

            $pdf = (string) file_get_contents($localOutput);
            $this->storePdfRepresentation($version, $disk, $pdf, [
                'tool' => 'phpspreadsheet+mpdf',
                'tool_version' => InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet'),
                'duration_ms' => $durationMs,
                'source_kind' => $version->extension,
            ]);

            return true;
        } catch (RuntimeException) {
            return false;
        } finally {
            @unlink($localOutput);
        }
    }

    private function tryLibreOffice(DocumentVersion $version, Filesystem $disk, string $localInput): bool
    {
        $outputDir = sys_get_temp_dir().'/xlsx_pdf_'.Str::uuid();

        try {
            File::ensureDirectoryExists($outputDir);
            $localOutput = $this->converter->toPdf($localInput, $outputDir);

            $pdf = (string) file_get_contents($localOutput);
            $this->storePdfRepresentation($version, $disk, $pdf, [
                'tool' => 'libreoffice',
                'tool_version' => $this->converter->version(),
            ]);

            return true;
        } catch (RuntimeException) {
            return false;
        } finally {
            File::deleteDirectory($outputDir);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storePdfRepresentation(DocumentVersion $version, Filesystem $disk, string $pdf, array $meta): void
    {
        $preparedPath = $this->preparedPath($version);
        $disk->put($preparedPath, $pdf);

        $this->representations->storeOrUpdate($version, RepresentationKind::Pdf, [
            'storage_path' => $preparedPath,
            'disk' => null,
            'size_bytes' => strlen($pdf),
            'mime' => 'application/pdf',
            'meta' => $meta,
            'generated_at' => now(),
        ]);
    }

    private function storeTextGrid(DocumentVersion $version, Filesystem $disk, string $localInput): void
    {
        $rendered = $this->textGridRenderer->render($localInput, $version->extension);

        if ($rendered === false) {
            return;
        }

        $preparedPath = $this->preparedTextGridPath($version);
        $disk->put($preparedPath, $rendered['text']);

        $this->representations->storeOrUpdate($version, RepresentationKind::TextGrid, [
            'storage_path' => $preparedPath,
            'disk' => null,
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
        $memoryLimit = (string) config('ai.prepare.xlsx_convert_memory_limit', '512M');
        $timeout = (int) config('ai.prepare.xlsx_convert_timeout_seconds', 120);

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
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.UnprocessableReason::ConversionFailed->value.": {$detail}",
        ]);
    }
}
