<?php

declare(strict_types=1);

namespace Platform\Process\Crawling;

use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Dto\Crawling\PageResultDto;
use Platform\Enums\Platform;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class EisCrawlingService extends AbstractCrawlingService
{
    public function getNodes(string $html): array
    {
        return (new DomCrawler($html))->filter('.search-registry-entry-block')->each(fn(DomCrawler $node) => $node);
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        /** @var DomCrawler $node */
        return new CrawlingItemDto(
            externalId: trim($node->filter('.registry-entry__header-mid__number')->text()),
            title: trim($node->filter('.registry-entry__body-value')->text()),
            url: platform_config(Platform::EIS->value)->get('url') . $node->filter('.registry-entry__header-mid__number a')->attr('href'),
        );
    }

    protected function parseHtmlToDto(string $html): PageResultDto
    {
        $nodes = $this->getNodes($html);
        $items = array_map(fn($node) => $this->crawlingItem($node), $nodes);

        $dom = new DomCrawler($html);
        $formParams = [];

        // Сбор hidden полей для JS-пагинации
        $dom->filter('form#searchForm input[type="hidden"]')->each(function (DomCrawler $input) use (&$formParams) {
            $formParams[$input->attr('name')] = $input->attr('value');
        });

        $currentPage = (int)($formParams['pageNumber'] ?? 1);
        $hasNextPage = $dom->filter('.paginator .next')->count() > 0;

        if ($hasNextPage) {
            $formParams['pageNumber'] = $currentPage + 1;
        }

        return new PageResultDto(
            items: $items,
            meta: new PageMetaDto(
                current_page: $currentPage,
                has_next_page: $hasNextPage,
                total_items: 0,
                items_per_page: 10,
                next_page_params: $formParams
            )
        );
    }
}
