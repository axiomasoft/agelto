<?php

declare(strict_types=1);

namespace Eis\Crawling;

use App\Enums\LogOutput;
use Carbon\Carbon;
use Platform\Dto\Attachments\AttachmentDto;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Dto\Crawling\PageResultDto;
use Platform\Models\Crawling\LinkAttachment;
use Platform\Process\Crawling\AbstractCrawlingService;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

final class EisCrawlingService extends AbstractCrawlingService
{
    /**
     * Извлечение узлов закупок из HTML.
     */
    public function getNodes(string $html): array
    {
        return (new DomCrawler($html))->filter('.search-registry-entry-block')->each(fn(DomCrawler $node) => $node);
    }

    /**
     * Парсинг конкретной закупки.
     */
    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        /** @var DomCrawler $node */
        $externalId = mb_trim($node->filter('.registry-entry__header-mid__number')->text());
        $title = mb_trim($node->filter('.registry-entry__body-value')->text());
        $url = 'https://zakupki.gov.ru' . $node->filter('.registry-entry__header-mid__number a')->attr('href');

        $priceText = $node->filter('.price-block__value')->count() ? $node->filter('.price-block__value')->text() : '0';
        $price = (float) preg_replace('/[^\d.]/', '', str_replace(',', '.', $priceText));

        return new CrawlingItemDto(
            externalId: $externalId,
            title: $title,
            url: $url,
            price: $price,
            publishedAt: $this->parseDate($node, 'Размещено'),
            updatedAt: $this->parseDate($node, 'Обновлено'),
            submissionEndAt: $this->parseDate($node, 'Окончание подачи'),
        );
    }

    public function getPageMeta(string $html, int $requestedPage = 1): PageMetaDto
    {
        $dom = new DomCrawler($html);
        $formParams = [];

        $dom->filter('form#searchForm input[type="hidden"]')->each(function (DomCrawler $input) use (&$formParams): void {
            $formParams[$input->attr('name')] = $input->attr('value');
        });

        // Используем переданный номер страницы: в HTML формы ЕИС поле pageNumber часто остаётся 1
        $currentPage = $requestedPage;

        $hasNextPage = $dom->filter('.paginator .paginator-button-next')->count() > 0;

        $nextPageParams = $formParams;
        if ($hasNextPage) {
            $nextPageParams['pageNumber'] = $currentPage + 1;
        }

        return new PageMetaDto(
            current_page: $currentPage,
            has_next_page: $hasNextPage,
            total_items: 0,
            items_per_page: 10,
            next_page_params: $nextPageParams
        );
    }

    /**
     * Сбор документов с вкладки "Документы" закупки.
     */
    protected function parseAttachments(string $commonInfoUrl): array
    {
        // Переходим с "Общей информации" на "Документы"
        $docsUrl = str_replace('common-info.html', 'documents.html', $commonInfoUrl);

        $page = $this->browser->openPage($docsUrl);
        // Задержка для подгрузки динамического контента ЕИС
        usleep(2000000);

        $html = $page->getHtml();
        $dom = new DomCrawler($html);

        $attachments = $dom->filter('.attachment a[href*="download"], a[href*="filestore"]')->each(function (DomCrawler $node) {
            $href = $node->attr('href');
            if (! $href || str_contains($href, 'signview') || str_contains($href, 'javascript')) {
                return null;
            }

            $fullUrl = str_starts_with($href, 'http') ? $href : 'https://zakupki.gov.ru' . $href;
            parse_str(parse_url($fullUrl, PHP_URL_QUERY) ?? '', $query);

            // UID для 44-ФЗ или ID для 223/прочих
            $externalId = $query['uid'] ?? ($query['id'] ?? md5($href));

            return new AttachmentDto(
                externalId: (string) $externalId,
                url: $fullUrl,
                name: preg_replace('/\s+/', ' ', mb_trim($node->attr('title') ?: $node->text()))
            );
        });

        // Удаляем пустые элементы и дубликаты
        $unique = array_values(array_filter(array_unique($attachments, SORT_REGULAR)));

        if (empty($unique)) {
            if (app()->environment('local')) {
                \Illuminate\Support\Facades\Storage::put('debug_eis_empty_docs.html', $html);
            }
            // Если документов нет, это подозрительно для ЕИС, кидаем исключение
            throw new RuntimeException("Документы не найдены для закупки: $docsUrl");
        }

        return $unique;
    }

    /**
     * Хелпер для парсинга дат из блоков ЕИС.
     */
    private function parseDate(DomCrawler $node, string $label): ?Carbon
    {
        $dateNode = $node->filter(".data-block__title:contains('$label') + .data-block__value");

        return $dateNode->count() ? Carbon::parse(mb_trim($dateNode->text())) : null;
    }
}
