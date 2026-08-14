<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\DocumentFormat;
use App\Enums\Procurement\Document\PreparationResult;

final class DocumentFormatRegistry
{
    /** @return array{format: DocumentFormat, mimes: list<string>, strategy: class-string<PreparationStrategy>, result: PreparationResult}|null */
    public function forExtension(string $extension): ?array
    {
        return match ($extension) {
            'pdf' => $this->entry(DocumentFormat::Pdf, ['application/pdf'], ScanStrategy::class),
            'xls' => $this->entry(DocumentFormat::Xls, ['application/vnd.ms-excel'], SpreadsheetStrategy::class),
            'xlsx' => $this->entry(DocumentFormat::Xlsx, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], SpreadsheetStrategy::class),
            'doc' => $this->entry(DocumentFormat::Doc, ['application/msword'], WordStrategy::class),
            'docx' => $this->entry(DocumentFormat::Docx, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], WordStrategy::class),
            'rtf' => $this->entry(DocumentFormat::Rtf, ['application/rtf', 'text/rtf'], WordStrategy::class),
            'odt' => $this->entry(DocumentFormat::Odt, ['application/vnd.oasis.opendocument.text'], WordStrategy::class),
            'png' => $this->entry(DocumentFormat::Png, ['image/png'], ImageStrategy::class),
            'jpg', 'jpeg' => $this->entry(DocumentFormat::Jpeg, ['image/jpeg'], ImageStrategy::class),
            'tif', 'tiff' => $this->entry(DocumentFormat::Tiff, ['image/tiff'], ImageStrategy::class),
            'bmp' => $this->entry(DocumentFormat::Bmp, ['image/bmp'], ImageStrategy::class),
            'webp' => $this->entry(DocumentFormat::Webp, ['image/webp'], ImageStrategy::class),
            'gsfx' => $this->entry(DocumentFormat::Gsfx, ['application/zip'], GsfxStrategy::class),
            'zip' => $this->entry(DocumentFormat::Zip, ['application/zip'], ArchiveStrategy::class, PreparationResult::ExpandedContainer),
            '7z' => $this->entry(DocumentFormat::SevenZip, ['application/x-7z-compressed'], ArchiveStrategy::class, PreparationResult::ExpandedContainer),
            'rar' => $this->entry(DocumentFormat::Rar, ['application/vnd.rar'], ArchiveStrategy::class, PreparationResult::ExpandedContainer),
            default => null,
        };
    }

    /**
     * @param  list<string>  $mimes
     * @param  class-string<PreparationStrategy>  $strategy
     * @return array{format: DocumentFormat, mimes: list<string>, strategy: class-string<PreparationStrategy>, result: PreparationResult}
     */
    private function entry(DocumentFormat $format, array $mimes, string $strategy, PreparationResult $result = PreparationResult::ReadyForScreening): array
    {
        return [
            'format' => $format,
            'mimes' => $mimes,
            'strategy' => $strategy,
            'result' => $result,
        ];
    }
}
