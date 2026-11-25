<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf as MpdfWriter;

/**
 * Excel→PDF выполняется отдельным PHP-процессом с memory_limit: загрузка большой книги
 * PhpSpreadsheet не должна исчерпать память соседних jobs. Landscape с fitToWidth(1)
 * и fitToHeight(0) сохраняет колонки на одной ширине, перенося строки между страницами.
 */
#[Description('Конвертирует Excel-файл в PDF (landscape, fitToWidth) для стадии подготовки документа')]
#[Signature('procurement:convert-xlsx-to-pdf {input} {output} {--max-rows= : Maximum total worksheet rows}')]
final class ConvertXlsxToPdfCommand extends Command
{
    public function handle(): int
    {
        IOFactory::registerWriter('Pdf', MpdfWriter::class);

        $input = (string) $this->argument('input');
        $reader = IOFactory::createReaderForFile($input);
        $maxRows = $this->option('max-rows');
        if ($maxRows !== null && (
            (! $reader instanceof Xls && ! $reader instanceof Xlsx)
            || preg_match('/^[1-9][0-9]*$/D', $maxRows) !== 1
            || array_sum(array_column($reader->listWorksheetInfo($input), 'totalRows')) > (int) $maxRows
        )) {
            return self::FAILURE;
        }
        $spreadsheet = $reader->load($input);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $sheet->getPageSetup()->setFitToWidth(1);
            $sheet->getPageSetup()->setFitToHeight(0);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Pdf');
        $writer->setPreCalculateFormulas(false);
        $writer->save((string) $this->argument('output'));

        $spreadsheet->disconnectWorksheets();

        return self::SUCCESS;
    }
}
