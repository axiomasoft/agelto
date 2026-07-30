<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Ai\Estimate\EstimatePassport;
use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Ai\Estimate\EstimateWorkType;
use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Dto\Procurement\Ai\Estimate\RowAttributes;
use App\Dto\Procurement\Ai\Estimate\RowModifier;
use App\Dto\Procurement\Ai\Estimate\RowResource;
use App\Dto\Procurement\Ai\Estimate\TotalLine;
use App\Dto\Procurement\Ai\Gsfx\GsfxAddZatr;
use App\Dto\Procurement\Ai\Gsfx\GsfxAddZatrGlava;
use App\Dto\Procurement\Ai\Gsfx\GsfxChapter;
use App\Dto\Procurement\Ai\Gsfx\GsfxCoefficient;
use App\Dto\Procurement\Ai\Gsfx\GsfxDocument;
use App\Dto\Procurement\Ai\Gsfx\GsfxPosition;
use App\Dto\Procurement\Ai\Gsfx\GsfxResource;
use App\Dto\Procurement\Ai\Gsfx\GsfxTzm2022;
use App\Dto\Procurement\Ai\Gsfx\GsfxWorkType;
use App\Dto\Procurement\Ai\Gsfx\GsfxWorkTypeGroup;
use App\Enums\Procurement\CalcMethod;
use App\Enums\Procurement\ResourceType;
use App\Enums\Procurement\RowType;
use App\Enums\Procurement\TotalKind;

/**
 * `GsfxDocument` (P3.3/P3.8/P3.9) → форма gold §8.2 (P5.1, D2/D94/D112/D113). Единственный метод
 * {@see self::toGold()} — БЕЗ записи на диск (P5.3). Соответствие Data.xml → §8.2 построчно:
 *
 * - `estimate_number` ← `Properties.txt: Attribs.LocNum`.
 * - `passport.address` ← `Attribs.ObjName`; `passport.target` НЕ заполняется — ObjName несёт
 *   свободный текст объекта, а не классификатор `EstimateTarget`, и словаря текст→enum ни один
 *   Input не даёт (гадать по подстроке значило бы завести словарь «от себя»).
 * - `passport.generator` ← литерал `ГрандСмета` + `GSmetaVersion` (`Document@Generator` ридер не
 *   читает — P3.3 Scope, D111 тальит его в `unmapped`; единственный источник версии — паспорт).
 * - `base_year` ← первое 4-значное число в `RegionInfo.RegionName` («ФСНБ-2022» → 2022);
 *   `price_level` ← та же строка целиком (единственная цитата уровня цен, которую несёт паспорт).
 * - `rows_expected` = число `<Position>` по всем главам (не разделов).
 * - `calc_method` ← `TotalCalcMethod` (`Res` → `resource`, иначе → `base_index`, D105 — других
 *   значений форма не предусматривает).
 * - `labor_hours_workers`/`labor_hours_machinists` — сумма `quantity` ресурсов `Tzr`/`Tzm`
 *   соответственно по всему документу (bcmath, без float); `Tzm2022` в сумму НЕ входит —
 *   `@Quantity ≡ 1` там ставка, а не часы (research/16 §2 п.1), иначе двойной счёт.
 * - `document_number`/`document_date`/`period_start`/`period_end`/`passport.contract_*` —
 *   реквизиты акта (SR-12), `.gsfx` их не несёт — остаются `null`.
 * - `work_types` — дерево `GsfxWorkTypeGroup[]` → `EstimateWorkType[]` (группа без нормативов,
 *   дети — `GsfxWorkType` с НР/СП); `EstimateRow::$work_type_name` резолвится по
 *   `GsfxPosition::$work_type_id` через плоский индекс `id → GsfxWorkType` по ВСЕМ группам всех
 *   каталогов сразу — на реальном эталоне единственный непустой каталог один («Виды работ
 *   2001г»), коллизии `id` между каталогами этот индекс не различает (нет свидетеля, нет и
 *   правила разрешения где-либо во входах).
 * - `totals.footer` — по главе `AddZatrGlava/@Glava` (главы без `<AddZatr>` пропускаются) плюс
 *   финальная строка `kind=total` из `Properties.txt: Total`; `kind` начислений НЕ доопределяется
 *   по `@Caption` — детерминированное доопределение по label спекой обещано, кодом не
 *   реализовано и назван долгом (`research/06-schema-decision.md` §7) — этот маппер его не
 *   закрывает молча.
 * - Многосметность (`research/07-multi-estimate-anchoring.md`): `.gsfx`-контейнер — ВСЕГДА один
 *   элемент `estimates[]` (единственный подтверждённый структурный эталон корпуса односметный,
 *   §1) — `row_index` считается СКВОЗНЫМ по этому единственному контейнеру, начиная с заголовка
 *   первой главы.
 */
final readonly class GsfxGoldMapper
{
    private const int MONEY_SCALE = 2;

    private const int QUANTITY_SCALE = 6;

    /**
     * Промежуточный масштаб `bcmul` перед финальным округлением до {@see self::MONEY_SCALE} —
     * не теряет точность на умножении количества на цену (Code Guidance: float — никогда).
     */
    private const int INTERMEDIATE_SCALE = 10;

    public function toGold(GsfxDocument $document): array
    {
        $workTypeIndex = $this->buildWorkTypeIndex($document->work_type_groups);

        $rows = [];
        $rowIndex = 0;

        foreach ($document->chapters as $chapter) {
            $rowIndex++;
            $rows[] = new EstimateRow(
                row_index: $rowIndex,
                row_type: RowType::SectionHeader,
                name: $chapter->caption,
            );

            foreach ($chapter->positions as $position) {
                $rowIndex++;
                $rows[] = $this->mapPositionRow($rowIndex, $position, $document, $workTypeIndex);
            }
        }

        $passport = $this->mapPassport($document);

        return [
            'estimates' => [
                array_merge($passport->toArray(), [
                    'rows' => array_map(static fn (EstimateRow $row): array => $row->toArray(), $rows),
                ]),
            ],
        ];
    }

    /**
     * Список неотображённых элементов/атрибутов `Data.xml` с частотой (Scope Included) —
     * `toGold()` их судьбу не решает, эта карта — вход сводного отчёта `estimate:gold:build` (P5.3).
     * `has_carrier` сегодня всегда `false`: после реопена P3.8/P3.9 в `unmapped` остаётся ровно
     * то, для чего утверждённое распределение носителя НЕ назвало (`research/16-gsfx-reader-
     * reopen.md` §4, `research/06-schema-decision.md` §6) — поле зарезервировано на случай
     * будущего расхождения (реопен добавит носитель, а этот маппер не обновят).
     *
     * @return array<string, array{count: int, has_carrier: bool}>
     */
    public function unmappedReport(GsfxDocument $document): array
    {
        $entries = $document->unmapped;

        if ($document->coefficients !== []) {
            // Document/Koefficients/K — глобальный список без ссылки на позицию (GsfxDocument
            // Scope: «по позициям не раскладывается»); носителя для него нет ни в одной таблице
            // research/06 §3 — не хранится, но не молчим об этом (Code Guidance).
            $entries['Document.coefficients'] = count($document->coefficients);
        }

        return array_map(
            static fn (int $count): array => ['count' => $count, 'has_carrier' => false],
            $entries,
        );
    }

    private function mapPassport(GsfxDocument $document): PassportResponse
    {
        $passport = $document->passport;

        $rowsExpected = array_sum(array_map(
            static fn (GsfxChapter $chapter): int => count($chapter->positions),
            $document->chapters,
        ));

        return new PassportResponse(
            passport: new EstimatePassport(
                type: null,
                reference: null,
                target: null,
                address: $passport->object_name,
                generator: $passport->program_version !== null
                    ? "ГрандСмета {$passport->program_version}"
                    : 'ГрандСмета',
                contract_number: null,
                contract_date: null,
            ),
            estimate_number: $passport->loc_num,
            currency: 'RUB',
            base_year: $this->resolveBaseYear($passport->normative_base),
            price_level: $passport->normative_base,
            rows_expected: $rowsExpected,
            totals: $this->mapTotals($document),
            calc_method: match ($passport->calc_method) {
                null => null,
                'Res' => CalcMethod::Resource,
                default => CalcMethod::BaseIndex,
            },
            labor_hours_workers: $this->sumResourceQuantity($document, 'Tzr'),
            labor_hours_machinists: $this->sumResourceQuantity($document, 'Tzm'),
            document_number: null,
            document_date: null,
            period_start: null,
            period_end: null,
            work_types: $this->mapWorkTypeGroups($document->work_type_groups),
        );
    }

    private function resolveBaseYear(?string $normativeBase): ?int
    {
        if ($normativeBase === null) {
            return null;
        }

        return preg_match('/(\d{4})/', $normativeBase, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * @param  array<string, GsfxWorkType>  $workTypeIndex
     */
    private function mapPositionRow(int $rowIndex, GsfxPosition $position, GsfxDocument $document, array $workTypeIndex): EstimateRow
    {
        $resources = [];

        foreach ($position->resources as $resource) {
            $resources[] = $this->mapResource($resource, $document);

            if ($resource->tzm2022 !== null) {
                $resources[] = $this->mapTzm2022Resource($resource, $resource->tzm2022);
            }
        }

        [$totalPriceBase, $totalPriceCurr] = $this->resolvePositionPrice($position);

        $modifiers = array_map($this->mapModifier(...), $position->coefficients);
        $workType = $position->work_type_id !== null ? ($workTypeIndex[$position->work_type_id] ?? null) : null;

        return new EstimateRow(
            row_index: $rowIndex,
            row_type: RowType::Item,
            name: $position->name,
            code: $position->code,
            unit: $position->unit,
            quantity: $this->normalizeDecimal($position->quantity),
            unit_price_base: null,
            unit_price_curr: null,
            total_price_base: $totalPriceBase,
            total_price_curr: $totalPriceCurr,
            index_code: $position->index_code,
            index_value: $this->resolvePositionIndexValue($position, $document),
            source_position_number: $position->number,
            work_type_name: $workType?->caption,
            resources: $resources,
            attributes: $modifiers === [] ? null : new RowAttributes(modifiers: $modifiers),
        );
    }

    /**
     * Цена позиции. 64 из 110 позиций эталона несут `PriceBase`/`PriceCurr` НАПРЯМУЮ («расценка
     * целиком по прямой цене каталога», D113 снятие эскалации) — это ИТОГ по строке, переносится
     * как есть. Остальные 46 раскладываются в `<Resources>` (ресурсный метод, `calc_method=
     * resource`, SR-17): `*_base` законно пуст (research/06 §5.1), `*_curr` считается прямым
     * счётом ресурсов — `Σ(quantity × price_curr)` по всем ресурсам позиции, кроме `Work`
     * (технологические строки без количества/цены, CalcMethod::Resource docblock: «цена
     * считается прямым счётом ресурсов текущего уровня»).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePositionPrice(GsfxPosition $position): array
    {
        if ($position->price_base !== null || $position->price_curr !== null) {
            return [
                $this->normalizeDecimal($position->price_base),
                $this->normalizeDecimal($position->price_curr),
            ];
        }

        if ($position->resources === []) {
            return [null, null];
        }

        $sum = null;

        foreach ($position->resources as $resource) {
            if ($resource->resource_type === 'Work') {
                continue;
            }

            $quantity = $this->normalizeDecimal($resource->quantity);
            $price = $this->normalizeDecimal($resource->price_curr);

            if ($quantity === null || $price === null) {
                continue;
            }

            $sum = bcadd($sum ?? '0', bcmul($quantity, $price, self::INTERMEDIATE_SCALE), self::INTERMEDIATE_SCALE);
        }

        return [null, $sum === null ? null : bcadd($sum, '0', self::MONEY_SCALE)];
    }

    private function resolvePositionIndexValue(GsfxPosition $position, GsfxDocument $document): ?string
    {
        if ($position->index_code === null || $document->index_catalog === null) {
            return null;
        }

        $entry = $document->index_catalog->positions[$position->index_code] ?? null;

        // Позиция разыменовывается составляющей `@MT` (материалы) — D94: «выбор составляющей
        // однозначно определяется типом ресурса», позиция без `<Resources>` ценится как материал.
        return $entry?->mt !== null ? $this->normalizeDecimal($entry->mt) : null;
    }

    private function mapResource(GsfxResource $resource, GsfxDocument $document): RowResource
    {
        return new RowResource(
            resource_type: $this->resolveResourceType($resource->resource_type),
            name: $resource->name,
            code: $resource->code,
            unit: $resource->unit,
            quantity: $this->normalizeDecimal($resource->quantity),
            price_base: $this->normalizeDecimal($resource->price_base),
            price_curr: $this->normalizeDecimal($resource->price_curr),
            index_code: $resource->index_code,
            index_value: $this->resolveResourceIndexValue($resource, $document),
        );
    }

    private function resolveResourceIndexValue(GsfxResource $resource, GsfxDocument $document): ?string
    {
        if ($resource->index_code === null || $document->index_catalog === null) {
            return null;
        }

        $entry = $document->index_catalog->resources[$resource->index_code] ?? null;

        if ($entry === null) {
            return null;
        }

        // D94: материал → `@MT`, машина → `@EM` (единственные два тега, реально несущие
        // IndexCode на эталоне — findings, проверено grep'ом по корпусу).
        $value = $resource->resource_type === 'Mat' ? $entry->mt : $entry->em;

        return $this->normalizeDecimal($value);
    }

    private function resolveResourceType(string $rawType): ResourceType
    {
        return match ($rawType) {
            'Mat' => ResourceType::Material,
            'Mch' => ResourceType::Machine,
            'Tzr', 'Tzm' => ResourceType::Labor,
            // Work — технологическое описание шага работ без количества/цены, не входит в 4
            // классических типа v1 (ResourceType::Other docblock: «задел под ресурсы, не
            // попадающие в 4 классических типа v1»).
            default => ResourceType::Other,
        };
    }

    /**
     * `Tzm2022` (P3.9) вложен ВНУТРЬ ресурсного тега (на эталоне — только `Mch`) и не имеет
     * собственного носителя строкой контракта — research/16 §1 таблица прямо называет носитель
     * `RowResource` с `resource_type=labor`: синтетическая строка-ресурс со значениями ставки
     * `Tzm2022`, ИМЯ берётся у родительского ресурса (то же оборудование/родительская запись,
     * не изобретается заново) — `unit` не переносится: у `Tzm2022` в `Data.xml` нет атрибута
     * единицы измерения (только `WorkClass`/`Quantity`/`Price`), а `unit` контракта — ЦИТАТА
     * документа; придумывать цитату, которой нет, запрещено. `Quantity ≡ 1` (ставка, не часы) —
     * в сводные `labor_hours_*` эта строка НЕ входит (см. {@see self::sumResourceQuantity()} —
     * считает только сиблинг-ресурсы `Tzr`/`Tzm`, `Tzm2022` не является `resource_type`).
     */
    private function mapTzm2022Resource(GsfxResource $parent, GsfxTzm2022 $tzm2022): RowResource
    {
        return new RowResource(
            resource_type: ResourceType::Labor,
            name: $parent->name,
            code: null,
            unit: null,
            quantity: $this->normalizeDecimal($tzm2022->quantity),
            price_base: null,
            price_curr: $this->normalizeDecimal($tzm2022->price),
            index_code: null,
            index_value: null,
        );
    }

    /**
     * `kind` коэффициента НЕ заполняется — источник не несёт различимого признака рода
     * (`qty`/`price`/`correction`/`winter`/`recalculation`), только `Options`-строку без
     * замкнутого словаря сопоставления в каком-либо Input; `value` (скалярная форма книг) тоже
     * `null` — `.gsfx` несёт составляющие, не единый скаляр (RowModifier docblock).
     */
    private function mapModifier(GsfxCoefficient $coefficient): RowModifier
    {
        return new RowModifier(
            name: $coefficient->caption,
            kind: null,
            code: $coefficient->code,
            value: null,
            value_labor: $this->normalizeDecimal($coefficient->value_oz),
            value_machines: $this->normalizeDecimal($coefficient->value_em),
            value_machinists_wage: $this->normalizeDecimal($coefficient->value_zm),
            value_materials: $this->normalizeDecimal($coefficient->value_mt),
            level: $coefficient->level !== null ? (int) $coefficient->level : null,
        );
    }

    private function sumResourceQuantity(GsfxDocument $document, string $resourceType): ?string
    {
        $sum = null;

        foreach ($document->chapters as $chapter) {
            foreach ($chapter->positions as $position) {
                foreach ($position->resources as $resource) {
                    if ($resource->resource_type !== $resourceType) {
                        continue;
                    }

                    $quantity = $this->normalizeDecimal($resource->quantity);

                    if ($quantity === null) {
                        continue;
                    }

                    $sum = bcadd($sum ?? '0', $quantity, self::QUANTITY_SCALE);
                }
            }
        }

        return $sum;
    }

    /**
     * @param  list<GsfxWorkTypeGroup>  $groups
     * @return list<EstimateWorkType>
     */
    private function mapWorkTypeGroups(array $groups): array
    {
        return array_map(
            static fn (GsfxWorkTypeGroup $group): EstimateWorkType => new EstimateWorkType(
                title: $group->caption,
                children: array_map(self::mapWorkType(...), $group->work_types),
            ),
            $groups,
        );
    }

    private static function mapWorkType(GsfxWorkType $workType): EstimateWorkType
    {
        return new EstimateWorkType(
            title: $workType->caption,
            overhead_code: $workType->nr_code,
            overhead_rate: self::normalizeDecimalStatic($workType->nacl),
            overhead_rate_curr: self::normalizeDecimalStatic($workType->nacl_curr),
            profit_code: $workType->sp_code,
            profit_rate: self::normalizeDecimalStatic($workType->plan),
            profit_rate_curr: self::normalizeDecimalStatic($workType->plan_curr),
        );
    }

    /**
     * Плоский индекс `GsfxWorkType::$id → GsfxWorkType` по ВСЕМ группам всех каталогов —
     * `Position@Vr2001` резолвится в дерево без учёта `catalog_type` (единственный непустой
     * каталог на эталоне один, коллизия `id` между каталогами не встречена ни разу и правила её
     * разрешения не даёт ни один Input).
     *
     * @param  list<GsfxWorkTypeGroup>  $groups
     * @return array<string, GsfxWorkType>
     */
    private function buildWorkTypeIndex(array $groups): array
    {
        $index = [];

        foreach ($groups as $group) {
            foreach ($group->work_types as $workType) {
                if ($workType->id !== null) {
                    $index[$workType->id] = $workType;
                }
            }
        }

        return $index;
    }

    private function mapTotals(GsfxDocument $document): EstimateTotalsBlock
    {
        $footer = [];

        foreach ($document->add_zatr_glavas as $glava) {
            if ($glava->items === []) {
                continue;
            }

            $chapterNumber = $this->resolveChapterNumber($glava);

            foreach ($glava->items as $addZatr) {
                $footer[] = $this->mapAddZatr($addZatr, $chapterNumber);
            }
        }

        if ($document->passport->total !== null) {
            $footer[] = new TotalLine(
                label: 'Итого по смете',
                value: $this->normalizeDecimal($document->passport->total),
                kind: TotalKind::Total,
            );
        }

        return new EstimateTotalsBlock(footer: $footer);
    }

    private function resolveChapterNumber(GsfxAddZatrGlava $glava): ?int
    {
        return $glava->glava !== null && ctype_digit($glava->glava) ? (int) $glava->glava : null;
    }

    /**
     * `AddZatr@Options` несёт `Percent`, когда `@Value` — ставка в процентах (SR-11): ставка идёт
     * в `rate`, `value` (сумма) остаётся `null` — и наоборот для прямой суммы. `@Formula` не
     * хранится (research/06 §6 п.6) — начисление без `@Value` (формула без прямого значения)
     * даёт ОБА поля `null`, не `"0"`.
     */
    private function mapAddZatr(GsfxAddZatr $addZatr, ?int $chapterNumber): TotalLine
    {
        $isPercent = $addZatr->options !== null && str_contains($addZatr->options, 'Percent');
        $normalizedValue = $this->normalizeDecimal($addZatr->value);

        return new TotalLine(
            label: $addZatr->caption,
            value: $isPercent ? null : $normalizedValue,
            kind: null,
            chapter_number: $chapterNumber,
            rate: $isPercent ? $normalizedValue : null,
        );
    }

    /**
     * Нормализация decimal-строки `.gsfx` (запятая-разделитель, разрядные пробелы, научная
     * нотация — напр. `Quantity="2E-5"` у малых расходуемых материалов) БЕЗ конверсии во float
     * (Code Guidance, research/06 §5.9 конфликт 5г) — пустая строка/`null` → `null`, не `"0"`.
     */
    private function normalizeDecimal(?string $raw): ?string
    {
        return self::normalizeDecimalStatic($raw);
    }

    private static function normalizeDecimalStatic(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        $withoutSpaces = str_replace([' ', "\u{00A0}"], '', $trimmed);
        $dotted = str_replace(',', '.', $withoutSpaces);

        return self::expandScientificNotation($dotted);
    }

    /**
     * `bcmath` (PHP 8.4) не принимает научную нотацию как валидный операнд — `bcadd('2E-5', …)`
     * бросает `ValueError`. Разворачивает мантиссу/экспоненту в позиционную запись ЧИСТОЙ строкой
     * (сдвиг десятичной точки), без прохода через `float` — источник decimal-точности не теряет
     * ни одной цифры, только меняет форму записи того же числа.
     */
    private static function expandScientificNotation(string $decimal): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $decimal, $matches) !== 1) {
            return $decimal;
        }

        $sign = $matches[1];
        $intPart = $matches[2];
        $fracPart = $matches[3];
        $exponent = (int) $matches[4];

        $digits = $intPart.$fracPart;
        $pointPosition = strlen($intPart) + $exponent;

        if ($pointPosition <= 0) {
            $digits = str_repeat('0', -$pointPosition + 1).$digits;
            $pointPosition = 1;
        } elseif ($pointPosition > strlen($digits)) {
            $digits .= str_repeat('0', $pointPosition - strlen($digits));
        }

        $integerResult = substr($digits, 0, $pointPosition);
        $fractionResult = substr($digits, $pointPosition);

        return $sign.$integerResult.($fractionResult !== '' ? ".{$fractionResult}" : '');
    }
}
