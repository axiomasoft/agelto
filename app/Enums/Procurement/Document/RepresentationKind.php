<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

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
     * PDF альтернативного конвертера (`LibreOfficeConverter`), производится САМИМ бенчмарком
     * (P6.1, D49) — отдельный kind, а не второй `pdf`: `unique(version_id, kind)` не даёт хранить
     * два PDF одного kind, а различие инструмента несёт `meta.tool`, не kind.
     */
    case PdfAlt = 'pdf_alt';

    /**
     * Расширение файла представления — нужно потребителю, который кладёт его во временный файл
     * перед upload'ом (`RepresentationResolver::forLlm()`).
     */
    public function extension(): string
    {
        return match ($this) {
            self::Pdf, self::PdfOcr, self::PdfAlt => 'pdf',
            self::Text, self::TextGrid => 'txt',
            self::StructuredXml => 'xml',
        };
    }
}
