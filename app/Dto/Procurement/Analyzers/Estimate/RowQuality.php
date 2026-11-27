<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\RowQualityReason;
use Spatie\LaravelData\Data;

/**
 * Контрактная форма носителя неопределённости позиции (research/12-uncertainty-model.md §3.1) — то, что модель кладёт в ответ на строку, которую не может считать надёжной целиком.
 * `EstimateRow::$quality` отсутствует (`null`) на чистой строке. Форма хранения БД (происхождение данных
 * ключом верхнего уровня `model`/`validator`) — другой объект, собирается на бэкенде, эта
 * структура переносится в него как есть под ключ `model`.
 */
final class RowQuality extends Data
{
    /**
     * @param  string[]  $fields
     */
    public function __construct(
        #[Description('причина, по которой перечисленные поля строки нельзя считать надёжными; модель вправе вернуть только unreadable — величину не удалось прочитать в источнике.')]
        public RowQualityReason $reason,
        #[Description('имена полей EstimateRow, которых касается причина (например quantity, total_price_curr); пустой список запрещён — пометка без адреса.')]
        public array $fields,
    ) {}
}
