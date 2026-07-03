<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

use App\Attributes\Alias;
use Spatie\LaravelData\Data;

/** Карта колонок таблицы сметы. Каждое поле — соответствие колонки по алиасам заголовка. */
final class EstimateHeader extends Data
{
    public function __construct(
        #[Alias(aliases: ['№ п/п', '№', 'поз'])]
        public ?EstimateHeaderColumn $pos,
        #[Alias(aliases: ['Шифр', 'Обоснование', 'Код'])]
        public ?EstimateHeaderColumn $code,
        #[Alias(aliases: ['Наименование работ', 'Описание'])]
        public ?EstimateHeaderColumn $name,
        #[Alias(aliases: ['Ед. изм.', 'Измеритель'])]
        public ?EstimateHeaderColumn $unit,
        #[Alias(aliases: ['Количество', 'Объем'])]
        public ?EstimateHeaderColumn $quantity,
        #[Alias(aliases: ['Стоимость всего', 'Итого руб'])]
        public ?EstimateHeaderColumn $price,
    ) {}
}
