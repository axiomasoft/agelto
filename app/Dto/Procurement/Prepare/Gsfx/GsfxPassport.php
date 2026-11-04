<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Паспорт сметы из `Properties.txt` контейнера ГрандСметы (ключи cp1251 key=value).
 * - doc_type: тип документа программы (`DocType`, напр. `sdtSmeta`).
 * - loc_num: номер сметы (`Attribs.LocNum`).
 * - object_name: наименование объекта (`Attribs.ObjName`).
 * - total: итоговая стоимость сметы, decimal-строка (`Total`).
 * - calc_method: метод расчёта (`TotalCalcMethod`, напр. `Res` — ресурсный).
 * - normative_base: нормативная база (`RegionInfo.RegionName`, напр. «ФСНБ-2022»).
 * - program_version: версия ГрандСметы, сформировавшей файл (`GSmetaVersion`).
 * - modified_at: дата последнего изменения, raw-строка формата `d.m.Y H:i:s` (`ModifyDate`) —
 *   не парсится в Carbon здесь, разбор формата — забота потребителя.
 */
final class GsfxPassport extends Data
{
    public function __construct(
        public ?string $doc_type = null,
        public ?string $loc_num = null,
        public ?string $object_name = null,
        public ?string $total = null,
        public ?string $calc_method = null,
        public ?string $normative_base = null,
        public ?string $program_version = null,
        public ?string $modified_at = null,
    ) {}
}
