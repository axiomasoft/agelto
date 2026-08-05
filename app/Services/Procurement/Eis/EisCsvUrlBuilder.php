<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Models\Procurement\Crawler\Crawler;
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

    /**
     * @return array<string, string>
     */
    public function buildQueryParams(Crawler $crawler, int $from, int $to, ?ListingCursor $cursor = null): array
    {
        $filter = $crawler->filter;

        if ($filter !== null && ! $filter instanceof EisSearchFilter) {
            throw new RuntimeException(
                'EisCsvUrlBuilder ожидает фильтр EisSearchFilter, получен '.$filter::class
                .' — проверьте карту config(\'crawler.filters\') для реестра профиля.'
            );
        }

        $columns = $crawler->options?->toArray()['columns'] ?? [];

        $query = $filter === null ? [] : $this->filterParams(filter: $filter);

        foreach ($columns as $column => $enabled) {
            if ($enabled === true) {
                $query[$column] = 'on';
            }
        }

        $query['from'] = (string) $from;
        $query['to'] = (string) $to;
        $query[$crawler->registry->code] = 'on';
        $query['sortBy'] = 'UPDATE_DATE';
        $query['sortDirection'] = 'false';
        // Обязательна для cap-детекта (`CsvSourceMethod::dedupSignature`) — запрашивается
        // всегда, независимо от `options.columns` профиля.
        $query[self::REGISTRY_NUMBER_COLUMN_FLAG] = 'on';

        if ($cursor?->day !== null) {
            $query = [...$query, ...$this->archiveOverrides(day: $cursor->day, priceStack: $cursor->priceStack ?? [], dateField: $cursor->dateField ?? 'publish')];
        }

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
    private function filterParams(EisSearchFilter $filter): array
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

        return $query;
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
