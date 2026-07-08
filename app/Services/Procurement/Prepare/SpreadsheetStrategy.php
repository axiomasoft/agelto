<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Console\Commands\Procurement\ConvertXlsxToPdfCommand;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use RuntimeException;

/**
 * Excel → PDF (landscape + fitToWidth(1)/fitToHeight(0), v1-урок против обрезки по столбцам,
 * `Old/.../Strategies/ExcelStrategy.php`) — xlsx size-gate (D35, research/L finding 5):
 * строки/размер проверяются ДЁШЕВО (`size_bytes` из БД + `listWorksheetInfo()` — метаданные
 * листа без загрузки данных в память) ДО полной загрузки; сверх лимита — `unprocessable(too_large)`,
 * не OOM. Сама конверсия — в ИЗОЛИРОВАННОМ PHP-процессе с явным `memory_limit`
 * ({@see ConvertXlsxToPdfCommand}), чтобы OOM не убивал
 * соседние jobs воркера.
 */
final readonly class SpreadsheetStrategy implements PreparationStrategy
{
    public function __construct(
        private DocumentStoreRepository $storeRepository,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        return in_array($version->extension, ['xls', 'xlsx'], true);
    }

    public function process(Document $document, DocumentVersion $version): void
    {
        if ($version->prepared_storage_path !== null) {
            return;
        }

        $maxMb = (float) config('ai.prepare.xlsx_max_mb', 25);
        if ($version->size_bytes > $maxMb * 1024 * 1024) {
            $this->markUnprocessable($document, "размер {$version->size_bytes} байт превышает лимит {$maxMb} МБ");

            return;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localInput = tempnam(sys_get_temp_dir(), 'xlsx_').'.'.$version->extension;
        $localOutput = tempnam(sys_get_temp_dir(), 'xlsx_pdf_').'.pdf';

        try {
            file_put_contents($localInput, $disk->get($version->storage_path));

            $maxRows = (int) config('ai.prepare.xlsx_max_rows', 20000);
            $rows = $this->countRows($localInput, $version->extension);

            if ($rows > $maxRows) {
                $this->markUnprocessable($document, "строк {$rows} превышает лимит {$maxRows}");

                return;
            }

            $this->convert($localInput, $localOutput);

            $preparedPath = $this->preparedPath($version);
            $disk->put($preparedPath, file_get_contents($localOutput));

            $this->storeRepository->updateVersion($version, ['prepared_storage_path' => $preparedPath]);
        } finally {
            @unlink($localInput);
            @unlink($localOutput);
        }
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

    private function markUnprocessable(Document $document, string $detail): void
    {
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.UnprocessableReason::TooLarge->value.": {$detail}",
        ]);
    }
}
