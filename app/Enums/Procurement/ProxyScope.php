<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Точка исхода трафика, для которой резолвер прокси выдаёт узел (research/02 §1, §4).
 */
enum ProxyScope: string
{
    case CrawlListing = 'crawl_listing';
    case CrawlDetail = 'crawl_detail';
    case DocumentDownload = 'document_download';
    case Llm = 'llm';
}
