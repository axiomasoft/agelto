<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\IncompleteReason;
use Spatie\LaravelData\Data;

/**
 * Буфер незавершённой строки на границе нарезки (docs/specs/v2/08-estimate-extraction.md §4б).
 * Семантика границы РАЗНАЯ в двух путях извлечения — не смешивать: в row-range пути буфер
 * заполняется только на границе ЗАПРОШЕННОГО диапазона строк (документ целиком в контексте,
 * постраничных разрывов для модели не существует); в оконном пути — на границе физической страницы
 * документа (page_number обязателен). Stitcher (бэкенд) сопоставляет буферы смежных окон/диапазонов
 * по составному ключу (код + наименование + страница) и склеивает разорванную строку в одну позицию
 * перед персистом.
 */
final class IncompleteRow extends Data
{
    public function __construct(
        #[Description('split_by_page_end (числа физически перенесены дальше — модель не додумывает цифры) или orphan_numbers_at_page_start (голые цифры без кода расценки — продолжение прошлой строки).')]
        public IncompleteReason $reason,
        #[Description('сырой текст обрывка строки как он есть в документе.')]
        public string $raw_text,
        #[Description('последний известный код расценки перед обрывом, если виден.')]
        public ?string $last_known_code = null,
        #[Description('номер физической страницы обрыва (заполняется только в оконном пути).')]
        public ?int $page_number = null,
    ) {}
}
