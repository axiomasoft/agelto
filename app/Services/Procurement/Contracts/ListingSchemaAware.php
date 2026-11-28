<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

/**
 * Необязательная способность `ListingRowMapper`: объявить колонки, без которых он не построит
 * НИ ОДНОГО снимка. `CsvBlockIngestor` проверяет заголовок блока до маппинга (preflight):
 * отсутствие такой колонки — дрейф схемы источника, а не битая строка, поэтому блок не
 * разбирается построчно, а отказ классифицируется как `SchemaDrift` (системный — пауза
 * источника, курсор на месте).
 *
 * Отдельный интерфейс, а не метод `ListingRowMapper`: способность нужна не каждому механизму
 * (будущий HTML/API-маппер может проверять структуру иначе), и контракт маппера не ломается.
 */
interface ListingSchemaAware
{
    /** @return list<string> */
    public function requiredColumns(): array;
}
