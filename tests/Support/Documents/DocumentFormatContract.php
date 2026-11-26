<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use App\Enums\Procurement\Document\DocumentFormat;
use App\Enums\Procurement\Document\PreparationResult;
use App\Services\Procurement\Prepare\ArchiveStrategy;
use App\Services\Procurement\Prepare\GsfxStrategy;
use App\Services\Procurement\Prepare\ImageStrategy;
use App\Services\Procurement\Prepare\ScanStrategy;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use App\Services\Procurement\Prepare\WordStrategy;

final class DocumentFormatContract
{
    /**
     * Единственный test-side шаблон расширения registry. Новый DocumentFormat обязан появиться
     * здесь с валидным MIME, terminal mismatch и точным handler/result до production-кода.
     *
     * @return array<string, array{extensions: list<string>, mimes: list<string>, strategy: class-string, result: PreparationResult, mismatch: string}>
     */
    public static function cases(): array
    {
        return [
            DocumentFormat::Pdf->value => self::entry(['pdf'], ['application/pdf'], ScanStrategy::class, 'text/plain'),
            DocumentFormat::Xls->value => self::entry(['xls'], ['application/vnd.ms-excel'], SpreadsheetStrategy::class, 'application/pdf'),
            DocumentFormat::Xlsx->value => self::entry(['xlsx'], ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], SpreadsheetStrategy::class, 'application/zip'),
            DocumentFormat::Ods->value => self::entry(['ods'], ['application/vnd.oasis.opendocument.spreadsheet'], SpreadsheetStrategy::class, 'application/zip'),
            DocumentFormat::Doc->value => self::entry(['doc'], ['application/msword'], WordStrategy::class, 'application/pdf'),
            DocumentFormat::Docx->value => self::entry(['docx'], ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], WordStrategy::class, 'application/zip'),
            DocumentFormat::Rtf->value => self::entry(['rtf'], ['application/rtf', 'text/rtf'], WordStrategy::class, 'application/pdf'),
            DocumentFormat::Odt->value => self::entry(['odt'], ['application/vnd.oasis.opendocument.text'], WordStrategy::class, 'application/zip'),
            DocumentFormat::Png->value => self::entry(['png'], ['image/png'], ImageStrategy::class, 'image/jpeg'),
            DocumentFormat::Jpeg->value => self::entry(['jpg', 'jpeg'], ['image/jpeg'], ImageStrategy::class, 'image/png'),
            DocumentFormat::Tiff->value => self::entry(['tif', 'tiff'], ['image/tiff'], ImageStrategy::class, 'image/png'),
            DocumentFormat::Bmp->value => self::entry(['bmp'], ['image/bmp'], ImageStrategy::class, 'image/png'),
            DocumentFormat::Webp->value => self::entry(['webp'], ['image/webp'], ImageStrategy::class, 'image/png'),
            DocumentFormat::Gsfx->value => self::entry(['gsfx'], ['application/zip'], GsfxStrategy::class, 'application/pdf'),
            DocumentFormat::Zip->value => self::entry(['zip'], ['application/zip'], ArchiveStrategy::class, 'application/pdf', PreparationResult::ExpandedContainer),
            DocumentFormat::SevenZip->value => self::entry(['7z'], ['application/x-7z-compressed'], ArchiveStrategy::class, 'application/zip', PreparationResult::ExpandedContainer),
            DocumentFormat::Rar->value => self::entry(['rar'], ['application/vnd.rar'], ArchiveStrategy::class, 'application/zip', PreparationResult::ExpandedContainer),
        ];
    }

    /** @param list<string> $extensions @param list<string> $mimes */
    private static function entry(array $extensions, array $mimes, string $strategy, string $mismatch, PreparationResult $result = PreparationResult::ReadyForScreening): array
    {
        return compact('extensions', 'mimes', 'strategy', 'result', 'mismatch');
    }
}
