<?php

declare(strict_types=1);

namespace Eis\Crawling;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Platform\Dto\Attachments\AttachmentDto;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Dto\Crawling\PageResultDto;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Platform\Process\Crawling\AbstractCrawlingService;
use Exception;

class EisCrawlingService extends AbstractCrawlingService
{
    public function getNodes(string $html): array
    {
        return (new DomCrawler($html))->filter('.search-registry-entry-block')->each(fn(DomCrawler $node) => $node);
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        /** @var DomCrawler $node */
        $externalId = trim($node->filter('.registry-entry__header-mid__number')->text());
        $title = trim($node->filter('.registry-entry__body-value')->text());
        $url = 'https://zakupki.gov.ru' . $node->filter('.registry-entry__header-mid__number a')->attr('href');

        $priceText = $node->filter('.price-block__value')->count() ? $node->filter('.price-block__value')->text() : '0';
        $price = (float) preg_replace('/[^\d.]/', '', str_replace(',', '.', $priceText));

        $item = new CrawlingItemDto(
            externalId: $externalId,
            title: $title,
            url: $url,
            price: $price,
            publishedAt: $this->parseDate($node, 'Размещено'),
            updatedAt: $this->parseDate($node, 'Обновлено'),
            submissionEndAt: $this->parseDate($node, 'Окончание подачи'),
        );

        // 2-й этап: Парсим документы (переименовано в parseAttachments)
        $item->attachments = $this->parseAttachments($url);

        dd($item);

        return $item;
    }

    protected function getPageInfo(string $html): PageResultDto
    {
        $nodes = $this->getNodes($html);
        $items = array_map(fn($node) => $this->crawlingItem($node), $nodes);

        $dom = new DomCrawler($html);
        $formParams = [];

        // Собираем токены формы для JS-пагинации
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
                next_page_params: $formParams
            )
        );
    }

    /**
     * Переход на вкладку "Документы" и сбор файлов
     */
    protected function parseAttachments(string $commonInfoUrl): array
    {
        $docsUrl = str_replace('common-info.html', 'documents.html', $commonInfoUrl);

        try {
            $response = Http::withRandomProxy()->get($docsUrl);
            if (!$response->successful()) return [];

            return (new DomCrawler($response->body()))->filter('.attachment__value a')->each(function (DomCrawler $node) {
                return new AttachmentDto(
                    externalId: md5($node->attr('href')),
                    url: 'https://zakupki.gov.ru' . $node->attr('href'),
                    name: trim($node->attr('title') ?: $node->text())
                );
            });
        } catch (Exception $e) {
            return [];
        }
    }

    private function parseDate(DomCrawler $node, string $label): ?Carbon
    {
        $dateNode = $node->filter(".data-block__title:contains('$label') + .data-block__value");
        return $dateNode->count() ? Carbon::parse(trim($dateNode->text())) : null;
    }
}
