<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis;

use App\Dto\Crawler\ListingCursor;
use App\Models\Crawler\Crawler;
use Illuminate\Support\Carbon;

/**
 * Query-параметры CSV-экспорта ЕИС (`orderCsvSettings/download.html`) из профиля
 * обхода (`crw_crawlers.filter`/`options.columns`), блока листинга и реестра
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
        $filter = $this->stringValues(values: $crawler->filter?->toArray() ?? []);
        $columns = $crawler->options?->toArray()['columns'] ?? [];

        $query = $filter;

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

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function stringValues(array $values): array
    {
        return array_map(static fn (mixed $value): string => (string) $value, $values);
    }
}
