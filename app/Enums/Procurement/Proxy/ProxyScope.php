<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Proxy;

use Filament\Support\Contracts\HasLabel;

/**
 * Точка исхода трафика, для которой резолвер прокси выдаёт узел (research/02 §1, §4).
 */
enum ProxyScope: string implements HasLabel
{
    case CrawlListing = 'crawl_listing';
    case CrawlDetail = 'crawl_detail';
    case DocumentDownload = 'document_download';
    case Llm = 'llm';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CrawlListing => 'Сбор списков',
            self::CrawlDetail => 'Сбор карточек',
            self::DocumentDownload => 'Загрузка документов',
            self::Llm => 'AI-анализ',
        };
    }
}
