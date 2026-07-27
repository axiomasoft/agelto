<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Вид представления версии документа (D25) — что именно лежит по `storage_path` записи
 * `prc_document_representations`. Хранится ТЕКСТОМ, не PG-enum: новый вид не должен требовать
 * миграции (требование универсальности владельца, `brief/01-refinements.md` §3).
 *
 * Кейса `original` НЕТ сознательно: оригинал — это `DocumentVersion::storage_path`,
 * запись о нём завела бы второй носитель одного факта.
 */
enum RepresentationKind: string
{
    /** Рендер исходника в PDF (Excel→PDF `SpreadsheetStrategy`, LibreOffice P3.4). */
    case Pdf = 'pdf';

    /** PDF со встроенным текстовым слоем после OCR (P3.7). */
    case PdfOcr = 'pdf_ocr';

    /** Плоский текст документа. */
    case Text = 'text';

    /** Компактная текстовая сетка таблицы (P3.5). */
    case TextGrid = 'text_grid';

    /** Машинная структурная модель ГрандСметы из `Data.xml` (P3.3). */
    case StructuredXml = 'structured_xml';

    /**
     * Расширение файла представления — нужно потребителю, который кладёт его во временный файл
     * перед upload'ом (`RepresentationResolver::forLlm()`).
     */
    public function extension(): string
    {
        return match ($this) {
            self::Pdf, self::PdfOcr => 'pdf',
            self::Text, self::TextGrid => 'txt',
            self::StructuredXml => 'xml',
        };
    }
}
