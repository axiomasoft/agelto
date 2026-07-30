<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Позиция сметы из `Data.xml` (P3.3, `<Chapter><Position>`).
 * - number: номер позиции (`Number`).
 * - code: шифр расценки (`Code`, напр. ФЕР/ТЕР/ФСБЦ).
 * - name: наименование работы (`Caption`).
 * - unit: единица измерения (`Units`).
 * - quantity: количество — `<Quantity Result="…">`, если есть, иначе атрибут `Quantity` самой
 *   позиции (формула как строка); decimal-строка как в XML, конверсия во float запрещена.
 * - index_code: собственный код индекса позиции (`Position@IndexCode`) — ссылка в каталог
 *   `GsfxDocument::$index_catalog->positions`; значение переносится КАК ЕСТЬ, разыменование в
 *   `@MT`/`@EM` — работа маппера P5.1 (D94). На эталоне непуст ровно у тех 64 из 110 позиций, что
 *   не несут дочернего `<Resources>` (`findings/P3-gsfx-reader-gaps-2026-07-27.md` §3).
 * - work_type_id: ссылка позиции на вид работ (`Position@Vr2001`), значение КАК ЕСТЬ — резолвится
 *   в `GsfxWorkType::$id` 110 из 110 на эталоне (findings §7); резолв в дерево `EstimateWorkType` —
 *   работа маппера P5.1 (P3.9, `research/16-gsfx-reader-reopen.md` §1).
 * - price_base: базовая цена позиции (`PriceBase/@Value`, при отсутствии — `@MT`, затем `@PZ`).
 * - price_curr: текущая цена позиции (`PriceCurr/@Value`, при отсутствии — `@MT`).
 * - indices: коды индексов пересчёта, СОБРАННЫЕ из `index_code` дочерних ресурсов (уникальные,
 *   в порядке первого появления) — агрегация ресурсных `Mat|Mch@IndexCode`, отдельная величина от
 *   `index_code` самой позиции: `Data.xml` хранит код индекса на разных носителях в зависимости от
 *   того, есть ли у позиции собственные ресурсы (позиция без `<Resources>` несёт `IndexCode` сама,
 *   позиция с `<Resources>` — на каждом ресурсе отдельно).
 * - coefficients: коэффициенты ПОЗИЦИИ из `Position/Koefficients/K` — связь с позицией выражена
 *   ВЛОЖЕННОСТЬЮ (P3.9, `research/16-gsfx-reader-reopen.md` §2), додумывать её не нужно. Документные
 *   `Document/Koefficients/K` — ОТДЕЛЬНЫЙ список без ссылки на конкретную позицию (`Options="InPos …"`
 *   — флаг применимости, не ID) — уходят в `GsfxDocument::$coefficients`, по позициям не раскладываются.
 * - resources: ресурсы позиции. «Трудозатраты» из ТЗ фазы — это записи `resources` с
 *   `resource_type` `Tzr`/`Tzm` (затраты труда рабочих/машинистов, разведка §3), отдельного поля
 *   не заводим — дублировало бы те же данные вторым носителем.
 *
 * @property list<GsfxResource> $resources
 * @property list<GsfxCoefficient> $coefficients
 */
final class GsfxPosition extends Data
{
    /**
     * @param  list<string>  $indices
     * @param  list<GsfxCoefficient>  $coefficients
     * @param  list<GsfxResource>  $resources
     */
    public function __construct(
        public string $name,
        public ?string $number = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $index_code = null,
        public ?string $work_type_id = null,
        public ?string $price_base = null,
        public ?string $price_curr = null,
        public array $indices = [],
        public array $coefficients = [],
        public array $resources = [],
    ) {}
}
