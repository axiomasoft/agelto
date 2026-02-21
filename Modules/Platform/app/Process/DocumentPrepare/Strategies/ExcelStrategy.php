<?php

declare(strict_types=1);

namespace Platform\Process\DocumentPrepare\Strategies;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ExcelStrategy implements PreparationStrategy
{
    public function canProcess(Media $media): bool
    {
        return in_array($media->extension, ['xls', 'xlsx']);
    }

    public function process(DocumentAnalysis $analysis, Media $media): void
    {
        if ($analysis->hasMedia(DocumentAnalysis::COLLECTION_DOCUMENT_PREPARED)) {
            return;
        }

        $tempPdf = tempnam(sys_get_temp_dir(), 'est_').'.pdf';

        try {
            IOFactory::registerWriter('Pdf', Mpdf::class);
            $spreadsheet = IOFactory::load($media->getPath());

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Pdf');
            $writer->save($tempPdf);
            $spreadsheet->disconnectWorksheets();

            $analysis->addMedia($tempPdf)
                ->usingFileName(pathinfo($media->file_name, PATHINFO_FILENAME).'.pdf')
                ->toMediaCollection(DocumentAnalysis::COLLECTION_DOCUMENT_PREPARED, config('app.filesystems.private_disk_name'));
        } finally {
            if (file_exists($tempPdf)) {
                unlink($tempPdf);
            }
        }
    }
}
