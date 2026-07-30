<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\IncompleteReason;
use Spatie\LaravelData\Data;

/**
 * Буфер незавершённой строки на границе нарезки (docs/specs/v2/08-estimate-extraction.md §4б,
 * приём v1 tmp/agelto2.md §3.3). Семантика границы РАЗНАЯ в двух путях извлечения — не смешивать:
 * в row-range пути буфер заполняется только на границе ЗАПРОШЕННОГО диапазона строк (документ
 * целиком в контексте, постраничных разрывов для модели не существует); в оконном пути — на
 * границе физической страницы документа (page_number обязателен). Stitcher (бэкенд, P6.2)
 * сопоставляет буферы смежных окон/диапазонов по составному ключу (код + наименование + страница)
 * и склеивает разорванную строку в одну позицию перед персистом.
 * - reason: split_by_page_end (числа физически перенесены дальше — модель не додумывает цифры)
 *   или orphan_numbers_at_page_start (голые цифры без кода расценки — продолжение прошлой строки).
 * - raw_text: сырой текст обрывка строки как он есть в документе.
 * - last_known_code: последний известный код расценки перед обрывом, если виден.
 * - page_number: номер физической страницы обрыва (заполняется только в оконном пути, §4б).
 */
final class IncompleteRow extends Data
{
    public function __construct(
        public IncompleteReason $reason,
        public string $raw_text,
        public ?string $last_known_code = null,
        public ?int $page_number = null,
    ) {}
}
