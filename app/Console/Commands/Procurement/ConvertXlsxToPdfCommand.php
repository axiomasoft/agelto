<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf as MpdfWriter;

/**
 * Excel→PDF выполняется отдельным PHP-процессом с memory_limit: загрузка большой книги
 * PhpSpreadsheet не должна исчерпать память соседних jobs. Landscape с fitToWidth(1)
 * и fitToHeight(0) сохраняет колонки на одной ширине, перенося строки между страницами.
 */
#[Description('Конвертирует Excel-файл в PDF (landscape, fitToWidth) для стадии подготовки документа')]
#[Signature('procurement:convert-xlsx-to-pdf {input} {output}')]
final class ConvertXlsxToPdfCommand extends Command
{
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
