<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gold;

use Spatie\LaravelData\Data;

/**
 * Одна часть доказательства привязки колонки профиля (P5.2, D117) — `estimates/gold/<CODE>.
 * profile.json` §`header_cells`. Группа-заголовок цитируется своим якорем merge (`cell` вне
 * колонки поля), подколонка — своей ячейкой (`cell` в колонке поля); `GridRecognitionGate`
 * условие (а) сверяет `text` дословно с фактическим содержимым ячейки по адресу `cell`.
 * - cell: адрес ячейки шапки листа (напр. «G25»).
 * - text: дословная цитата содержимого этой ячейки на момент разметки.
 */
final class GoldHeaderQuote extends Data
{
    public function __construct(
        public string $cell,
        public string $text,
    ) {}
}
