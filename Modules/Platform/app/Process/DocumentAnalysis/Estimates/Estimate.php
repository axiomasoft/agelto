<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Estimates;

use App\Services\GeminiService;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class Estimate
{
    public function __construct(
        private GeminiService $geminiService,
    ) {}

    public function parse(DocumentAnalysis $documentAnalysis): void
    {
        $document = $documentAnalysis->getFirstMedia('document');

        if (! $document) {
            throw new Exception('Document not found');
        }

        if (in_array($document->extension, ['xls', 'xlsx'])) {
            $document = $this->convertExcelToPdfAndAttach($documentAnalysis, $document->getPath());
        }

        switch ($document->extension) {
            case 'pdf':

                break;
            default:
                throw new Exception('Unsupported document type');
        }
    }

    /**
     * Конвертирует Excel-файл в PDF и записывает в коллекцию document-prepared.
     *
     * @return Media запись добавленного PDF в медиа-коллекции
     */
    private function convertExcelToPdfAndAttach(DocumentAnalysis $documentAnalysis, string $excelPath): Media
    {
        $document = $documentAnalysis->getFirstMedia('document-prepared');

        if ($document) {
            return $document;
        }

        try {
            IOFactory::registerWriter('Pdf', Mpdf::class);

            $spreadsheet = IOFactory::load($excelPath);
            $writer = IOFactory::createWriter($spreadsheet, 'Pdf');

            $tempPdf = tempnam(sys_get_temp_dir(), 'estimate_') . '.pdf';
            $writer->save($tempPdf);

            unset($spreadsheet, $writer);
            $documentAnalysis->clearMediaCollection('document-prepared');
            $media = $documentAnalysis->addMedia($tempPdf)
                ->usingFileName(pathinfo($excelPath, PATHINFO_FILENAME) . '.pdf')
                ->toMediaCollection('document-prepared', config('app.filesystems.private_disk_name'));

            return $media;
        } finally {
            if (isset($tempPdf) && is_file($tempPdf)) {
                unlink($tempPdf);
            }
        }
    }
}
