<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Позиция сметы из `Data.xml` (P3.3, `<Chapter><Position>`).
 * - number: номер позиции (`Number`).
 * - code: шифр расценки (`Code`, напр. ФЕР/ТЕР/ФСБЦ).
 * - name: наименование работы (`Caption`).
 * - unit: единица измерения (`Units`).
 * - quantity: количество — `<Quantity Result="…">`, если есть, иначе атрибут `Quantity` самой
 *   позиции (формула как строка); decimal-строка как в XML, конверсия во float запрещена.
 * - price_base: базовая цена позиции (`PriceBase/@Value`, при отсутствии — `@MT`, затем `@PZ`).
 * - price_curr: текущая цена позиции (`PriceCurr/@Value`, при отсутствии — `@MT`).
 * - indices: коды индексов пересчёта, СОБРАННЫЕ из `index_code` дочерних ресурсов (уникальные,
 *   в порядке первого появления) — в самом `Data.xml` индекс не хранится на позиции напрямую,
 *   только на ресурсе (`IndexCode`), это агрегация, не отдельный XML-элемент.
 * - coefficients: коэффициенты позиции — поле контракта DTO; в разобранном корпусе `<Koefficients>`
 *   существует ГЛОБАЛЬНЫМ каталогом без атрибута, однозначно связывающего запись с конкретной
 *   позицией (`Options="InPos …"` — флаг применимости, не ссылка на ID) — остаётся пустым списком,
 *   не додумываем связь; каталог целиком уходит в `GsfxDocument::$unmapped['Koefficients']`.
 * - resources: ресурсы позиции. «Трудозатраты» из ТЗ фазы — это записи `resources` с
 *   `resource_type` `Tzr`/`Tzm` (затраты труда рабочих/машинистов, разведка §3), отдельного поля
 *   не заводим — дублировало бы те же данные вторым носителем.
 *
 * @property list<GsfxResource> $resources
 */
final class GsfxPosition extends Data
{
    /**
     * @param  list<string>  $indices
     * @param  list<string>  $coefficients
     * @param  list<GsfxResource>  $resources
     */
    public function __construct(
        public string $name,
        public ?string $number = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $price_base = null,
        public ?string $price_curr = null,
        public array $indices = [],
        public array $coefficients = [],
        public array $resources = [],
    ) {}
}
