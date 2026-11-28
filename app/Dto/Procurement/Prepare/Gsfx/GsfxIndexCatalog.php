<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Каталог индексов пересчёта прямого потомка `Document/Indexes` — ДВА раздельных
 * пространства кодов (`IndexesPos`/`IndexesRes`), пересекающиеся числами при разном смысле
 * (`research/16-gsfx-reader-reopen.md` §3): один и тот же `@Code` встречается в обоих подкаталогах
 * с разными записями, поэтому разыменование адресуется по типу носителя (позиция → `positions`,
 * ресурс → `resources`), общий словарь дал бы молча неверное значение. Каталог акта
 * (`ImplemActs/ImplemAct/Indexes`) в эту модель не входит (отдельный
 * каталог акта не разбирается вовсе).
 *
 * @property array<string, GsfxIndex> $positions
 * @property array<string, GsfxIndex> $resources
 */
final class GsfxIndexCatalog extends Data
{
    /**
     * @param  array<string, GsfxIndex>  $positions  Ключ — `IndexesPos/Index@Code`.
     * @param  array<string, GsfxIndex>  $resources  Ключ — `IndexesRes/Index@Code`.
     */
    public function __construct(
        public array $positions = [],
        public array $resources = [],
    ) {}
}
