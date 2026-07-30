<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Запись каталога индексов пересчёта `Data.xml` (P3.8, `<Indexes><IndexesPos|IndexesRes><Index>`).
 * - code: код индекса (`Code`) — ключ каталога (`GsfxIndexCatalog`).
 * - caption: название индекса (`Caption`).
 * - mt: составляющая «материалы+техника» (`MT`), decimal-строка как в XML.
 * - em: составляющая «эксплуатация машин» (`EM`), decimal-строка как в XML.
 * Ровно одно из `mt`/`em` непусто в подавляющем большинстве записей, но обе-пустая запись законна
 * (на эталоне одна такая, `Code="312"`). Выбор составляющей по типу ресурса — работа маппера P5.1
 * (D94), ридер несёт обе значения как есть, без выбора.
 */
final class GsfxIndex extends Data
{
    public function __construct(
        public string $code,
        public string $caption,
        public ?string $mt = null,
        public ?string $em = null,
    ) {}
}
