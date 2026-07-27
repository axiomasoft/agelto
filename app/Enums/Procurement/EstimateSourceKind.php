<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Система координат адреса сметы в многосметном документе (D106,
 * `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §11) — ПРОИЗВОДНОЕ значение,
 * колонки не имеет: вычисляется аксессором `Estimate::sourceKind()` из уже заполненных
 * `source_sheet_name`/`source_page_start` (дискриминатор в схеме порождал бы рассинхрон, а не
 * информацию — тот же довод, которым D100 удалила вычислимый `title`).
 * - WholeDocument: адрес не назначен — документ несёт ровно одну смету целиком.
 * - Pages: адрес — диапазон страниц исходного документа (оконный путь извлечения, скан).
 * - Sheet: адрес — лист книги (`.gsfx`/книга Excel).
 */
enum EstimateSourceKind: string
{
    case WholeDocument = 'whole_document';
    case Pages = 'pages';
    case Sheet = 'sheet';
}
