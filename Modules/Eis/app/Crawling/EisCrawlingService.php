<?php

declare(strict_types=1);

namespace Eis\Crawling;

use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Process\Crawling\AbstractCrawlingService;
use Symfony\Component\DomCrawler\Crawler;

final class EisCrawlingService extends AbstractCrawlingService
{
    public function getNodes(string $html): array
    {
        $crawler = new Crawler($html);

        return $crawler->filter('.search-registry-entry-block')->each(fn($node) => $node);
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        $link = $node->filter('.registry-entry__header-mid__number a');

        dd($link);

        return new CrawlingItemDto(
            externalId: preg_replace('/[^0-9]/', '', $link->text()),
            url: 'https://zakupki.gov.ru' . $link->attr('href'),
            title: mb_trim($node->filter('.registry-entry__body-value')->text())
        );
    }
}
