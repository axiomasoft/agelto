<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Proxy\ProxyRouteSource;
use Illuminate\Support\Carbon;

/**
 * Один блок листинга, как его вернул `SourceMethod::collectListing()` — сырые строки
 * (ассоциативы по заголовку CSV) + метаданные забора для `prc_fetches` и cap-детекта
 * (`02-crawling.md` §3.3–§3.4). Нормализация в снапшот закупки — вне DTO (`ListingRowMapper`, P2.5).
 */
final readonly class ListingBatch
{
    /**
     * `$rowsTotal` — число записей данных, которые вернул источник (без заголовка), ВКЛЮЧАЯ
     * `$rowsMalformed`: стоп-условие «блок неполный» (`ArchiveWindowScanner`) должно видеть размер
     * ответа сервера, а не число успешно разобранных строк — иначе одна битая запись выглядит как
     * последний блок окна и остаток окна тихо теряется.
     *
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $requestParams
     */
    public function __construct(
        public array $rows,
        public string $rawBody,
        public ?string $dedupSignature,
        public int $rowsTotal,
        public int $httpStatus,
        public int $durationMs,
        public array $requestParams,
        public ?int $proxyId = null,
        public ?ProxyRouteSource $proxySource = null,
        public int $waitMs = 0,
        public int $rowsMalformed = 0,
        /** @var list<string>|null Заголовок CSV как пришёл; null — тела нет или источник не CSV. */
        public ?array $header = null,
        /**
         * Источник ответил 2xx с пустым телом (ни байта данных). Ставит адаптер, который знает
         * свой формат; пустая выдача (заголовок без строк) — не пустое тело, а конец данных.
         */
        public bool $emptyBody = false,
        public ?Carbon $observedAt = null,
    ) {}
}
