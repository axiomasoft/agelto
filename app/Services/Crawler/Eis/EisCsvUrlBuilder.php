<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis;

use App\Models\Crawler\Crawler;

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
    public function buildQueryParams(Crawler $crawler, int $from, int $to): array
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

        return $query;
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
