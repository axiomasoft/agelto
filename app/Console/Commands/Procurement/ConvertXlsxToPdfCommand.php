<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf as MpdfWriter;

/**
 * Конвертация Excel→PDF в ИЗОЛИРОВАННОМ процессе (D35, research/L finding 5): PhpSpreadsheet
 * грузит ~126 МБ RAM на 10k строк только на `load()` — запуск отдельным `php`-процессом
 * с явным `memory_limit` не даёт OOM убить соседние jobs воркера
 * ({@see SpreadsheetStrategy}). Landscape + fitToWidth(1)/
 * fitToHeight(0) — v1-урок (`Old/.../Strategies/ExcelStrategy.php`), иначе смета режется
 * по столбцам вместо переноса на следующую страницу.
 */
final class ConvertXlsxToPdfCommand extends Command
{
    protected $signature = 'procurement:convert-xlsx-to-pdf {input} {output}';

    protected $description = 'Конвертирует Excel-файл в PDF (landscape, fitToWidth) для стадии подготовки документа';

    public function handle(): int
    {
        IOFactory::registerWriter('Pdf', MpdfWriter::class);

        $spreadsheet = IOFactory::load((string) $this->argument('input'));

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $sheet->getPageSetup()->setFitToWidth(1);
            $sheet->getPageSetup()->setFitToHeight(0);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Pdf');
        $writer->save((string) $this->argument('output'));

        $spreadsheet->disconnectWorksheets();

        return self::SUCCESS;
    }
}
