<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Dto\Procurement\Crawler\EisListingQueryOverrides;
use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Query-параметры CSV-экспорта ЕИС (`orderCsvSettings/download.html`) из профиля
 * обхода (`prc_crawlers.filter`/`options.columns`), блока листинга и реестра
 * (`fz44`/`fz223`). ЕИС-специфика (имена флагов, сортировка) живёт только здесь.
 */
final readonly class EisCsvUrlBuilder
{
    /**
     * Флаг колонки CSV-экспорта «Реестровый номер закупки» (`research/eis-csv-recon.md`) —
     * источник `dedupSignature` для cap-детекта, запрашивается всегда.
     */
    public const string REGISTRY_NUMBER_COLUMN_FLAG = 'registryNumberCsv';

    public function __construct(
        private Okpd2NodeReadRepository $okpd2Nodes,
    ) {}

    /**
     * Собирает параметры для всех путей листинга. Если курсор дренирует день, он —
     * единственный источник дат: archive использует дату публикации, delta-scan и
     * backlog-rescan — дату обновления; fresh без дня курсора не затрагивается (D113/D115).
     *
     * @return array<string, string>
     */
    public function buildQueryParams(
        Crawler $crawler,
        int $from,
        int $to,
        ?ListingCursor $cursor = null,
        ?EisListingQueryOverrides $overrides = null,
    ): array {
        $filter = $crawler->filter;

        if ($filter !== null && ! $filter instanceof EisSearchFilter) {
            throw new RuntimeException(
                'EisCsvUrlBuilder ожидает фильтр EisSearchFilter, получен '.$filter::class
                .' — проверьте карту config(\'crawler.filters\') для реестра профиля.'
            );
        }

        $columns = $crawler->options?->toArray()['columns'] ?? [];

        $query = $filter === null ? [] : $this->filterParams(
            filter: $filter,
            registry: $this->requireRegistry($crawler),
            includeOkpd2: ! ($overrides !== null && $overrides->withoutOkpd2),
        );

        foreach ($columns as $column => $enabled) {
            if ($enabled === true) {
                $query[$column] = 'on';
            }
        }

        $query['from'] = (string) $from;
        $query['to'] = (string) $to;
        $query[$crawler->registry->code] = 'on';
        [$query['sortBy'], $query['sortDirection']] = $this->sortParams(filter: $filter);
        // Обязательна для cap-детекта (`CsvSourceMethod::dedupSignature`) — запрашивается
        // всегда, независимо от `options.columns` профиля.
        $query[self::REGISTRY_NUMBER_COLUMN_FLAG] = 'on';

        if ($overrides !== null) {
            $query = $this->withoutFilterDateWindow(query: $query);
            $query = [...$query, ...$this->publishedDateWindowParams(overrides: $overrides)];
        }

        if ($cursor?->day !== null) {
            $query = $this->withoutFilterDateWindow(query: $query);
            $query = [...$query, ...$this->archiveOverrides(day: $cursor->day, priceStack: $cursor->priceStack ?? [], dateField: $cursor->dateField ?? 'publish')];
        }

        return $query;
    }

    /**
     * Курсор archive/delta/backlog уже задаёт один конкретный день, поэтому профильное окно
     * публикации не должно остаться второй датовой парой в query (D113/D115). Снимаем обе пары
     * симметрично: сегодня фильтр эмитит только `publishDate*`, но правило не должно протечь,
     * если профиль начнёт хранить окно обновления.
     *
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    private function withoutFilterDateWindow(array $query): array
    {
        unset(
            $query['publishDateFrom'],
            $query['publishDateTo'],
            $query['updateDateFrom'],
            $query['updateDateTo'],
        );

        return $query;
    }

    /**
     * Перевод типизированного фильтра в query-параметры ЕИС. Имена параметров (`af`, `pc`,
     * `priceFromGeneral`, …) и формат даты — ЕИС-специфика и живут ТОЛЬКО здесь: DTO несёт смысл
     * поля, а не представление площадки (Code Guidance P4.3). Соответствие «поле → параметр» —
     * `research/05-change-tracking.md` раздел 4.
     *
     * Выключенный этап и незаполненное поле ключа НЕ эмитят: ЕИС трактует присутствие флага как
     * включённый фильтр, а `priceToGeneral=0` обнуляет выдачу (`findings/P4-eis-recon.md` §5.2).
     *
     * @return array<string, string>
     */
    private function filterParams(EisSearchFilter $filter, Registry $registry, bool $includeOkpd2 = true): array
    {
        $dateFormat = (string) config('crawler.archive.date_format', 'd.m.Y');

        $params = [
            'af' => $filter->stageApplications,
            'ca' => $filter->stageCommission,
            'pc' => $filter->stageCompleted,
            'pa' => $filter->stageCancelled,
            'morphology' => $filter->morphology,
        ];

        $query = [];

        foreach ($params as $key => $enabled) {
            if ($enabled === true) {
                $query[$key] = 'on';
            }
        }

        if ($filter->priceFrom !== null) {
            $query['priceFromGeneral'] = (string) $filter->priceFrom;
        }

        if ($filter->priceTo !== null) {
            $query['priceToGeneral'] = (string) $filter->priceTo;
        }

        if ($filter->publishedFrom !== null) {
            $query['publishDateFrom'] = $filter->publishedFrom->format($dateFormat);
        }

        if ($filter->publishedTo !== null) {
            $query['publishDateTo'] = $filter->publishedTo->format($dateFormat);
        }

        if ($filter->searchString !== null && $filter->searchString !== '') {
            $query['searchString'] = $filter->searchString;
        }

        return $includeOkpd2
            ? [...$query, ...$this->okpd2Params(filter: $filter, registry: $registry)]
            : $query;
    }

    /**
     * Дата healthcheck-запроса — временное свойство именно запроса, а не persisted-профиля:
     * иначе для нефильтрованной стороны пришлось бы сохранять невалидный пустой scope. ЕИС-имена
     * и формат даты по-прежнему инкапсулированы только этим builder'ом.
     *
     * @return array<string, string>
     */
    private function publishedDateWindowParams(EisListingQueryOverrides $overrides): array
    {
        $dateFormat = (string) config('crawler.archive.date_format', 'd.m.Y');

        return [
            'publishDateFrom' => $overrides->publishedFrom->format($dateFormat),
            'publishDateTo' => $overrides->publishedTo->format($dateFormat),
        ];
    }

    /**
     * Резолв кодов ОКПД2 упирается в `platform_id` реестра профиля (P2.1) — без загруженной
     * связи `registry` резолвить нечего, отказ явный (тот же класс защиты, что
     * `Crawler::resolveFilter()` на несохранённой модели).
     */
    private function requireRegistry(Crawler $crawler): Registry
    {
        $registry = $crawler->registry;

        if (! $registry instanceof Registry) {
            throw new RuntimeException(
                'EisCsvUrlBuilder не резолвит фильтр без реестра профиля — связь `registry` не загружена.'
            );
        }

        return $registry;
    }

    /**
     * Сортировка выдачи (`sortBy`/`sortDirection`) из профиля (P3.1). `sortDirection`
     * ИНВЕРТИРОВАН на площадке: `'false'` даёт УБЫВАНИЕ, `'true'` — возрастание
     * (`findings/eis-params-carryover.md` §1, живой замер P1.1) — DTO хранит положительное
     * `sortAscending`, инверсию выполняет только этот метод. `sortField === null` или
     * `filter === null` эмитят прежний дефолт (`UPDATE_DATE`/`false`) байт-в-байт: item обязан
     * быть поведенчески нейтральным для уже лежащих в БД профилей. Archive/delta-курсор
     * ПЕРЕКРЫВАЕТ направление через `archiveOverrides()` (:`sortDirection` → `'true'`)
     * БЕЗУСЛОВНО и намеренно — в этой ветке окно и порядок дренажа ставит сканер, профильная
     * сортировка на неё не распространяется.
     *
     * @return array{0: string, 1: string}
     */
    private function sortParams(?EisSearchFilter $filter): array
    {
        $sortField = $filter?->sortField;

        if ($sortField === null) {
            return ['UPDATE_DATE', 'false'];
        }

        $sortBy = match ($sortField) {
            ListingSortField::Published => 'PUBLISH_DATE',
            ListingSortField::Updated => 'UPDATE_DATE',
        };

        return [$sortBy, $filter->sortAscending ? 'true' : 'false'];
    }

    /**
     * Резолв предметного скоупа профиля (`okpd2Codes`, коды) в `okpd2Ids` (внутренние id карты
     * `prc_okpd2_nodes` площадки реестра) — единственная рабочая форма фильтра ЕИС
     * (`findings/P1-okpd2-probe.md` §2, §3): без `okpd2IdsWithNested=on` закупки, ссылающиеся на
     * листовые коды, не совпадают с узлом группы, поэтому флаг эмитится безусловно.
     *
     * @return array<string, string>
     */
    private function okpd2Params(EisSearchFilter $filter, Registry $registry): array
    {
        if ($filter->okpd2Codes === []) {
            // Ветка только ради обратной совместимости с профилями, сохранёнными до
            // обязательности скоупа (D18/P2.2) — новый профиль пустым `okpd2Codes` не сохранить.
            return [];
        }

        $externalIds = $this->okpd2Nodes->externalIds(platformId: $registry->platform_id, codes: $filter->okpd2Codes);

        $unresolved = array_diff($filter->okpd2Codes, array_keys($externalIds));

        if ($unresolved !== []) {
            throw new RuntimeException(
                'Не резолвятся коды ОКПД2 в карте узлов площадки: '.implode(', ', $unresolved)
                .' — прогоните пересборку карты (P2.5) и повторите.'
            );
        }

        $codes = $filter->okpd2Codes;
        sort($codes, SORT_STRING);

        return [
            'okpd2Ids' => implode(',', array_map(static fn (string $code): string => $externalIds[$code], $codes)),
            'okpd2IdsWithNested' => 'on',
        ];
    }

    /**
     * Archive-ветка (`02-crawling.md` §4.2, D21): прогон по дням с ценовым дроблением —
     * ЕИС-специфика (имена параметров, формат даты) живёт только тут, курсор
     * (`cursor->day`/`priceStack`) остаётся агностиком.
     *
     * @param  list<string>  $priceStack
     * @param  'publish'|'update'  $dateField
     * @return array<string, string>
     */
    private function archiveOverrides(string $day, array $priceStack, string $dateField): array
    {
        $formattedDay = Carbon::createFromFormat('Y-m-d', $day)->format((string) config('crawler.archive.date_format', 'd.m.Y'));
        [$priceFrom, $priceTo] = $this->currentPriceInterval(priceStack: $priceStack);

        $fromKey = $dateField === 'update' ? 'updateDateFrom' : 'publishDateFrom';
        $toKey = $dateField === 'update' ? 'updateDateTo' : 'publishDateTo';

        return [
            $fromKey => $formattedDay,
            $toKey => $formattedDay,
            'priceFromGeneral' => $priceFrom,
            'priceToGeneral' => $priceTo,
            'sortDirection' => 'true',
        ];
    }

    /**
     * @param  list<string>  $priceStack
     * @return array{0: string, 1: string}
     */
    private function currentPriceInterval(array $priceStack): array
    {
        $top = $priceStack[array_key_last($priceStack)] ?? '0:0';
        [$from, $to] = explode(':', $top);

        return [$from, $to];
    }
}
