<?php

declare(strict_types=1);

namespace Eis\Crawling;

use Carbon\Carbon;
use RuntimeException;
use Platform\Dto\Attachments\AttachmentDto;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Dto\Crawling\PageResultDto;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Platform\Process\Crawling\AbstractCrawlingService;
use App\Enums\LogOutput;

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

        $this->logger->info("Парсинг вложений для $externalId", LogOutput::Console);
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

        // Собираем скрытые поля формы поиска для пагинации
        $dom->filter('form#searchForm input[type="hidden"]')->each(function (DomCrawler $input) use (&$formParams) {
            $formParams[$input->attr('name')] = $input->attr('value');
        });

        $currentPage = (int)($formParams['pageNumber'] ?? 1);
        $hasNextPage = $dom->filter('.paginator .next')->count() > 0;

        // Подготавливаем параметры для следующей страницы
        $nextPageParams = $formParams;
        if ($hasNextPage) {
            $nextPageParams['pageNumber'] = $currentPage + 1;
        }

        return new PageResultDto(
            items: $items,
            meta: new PageMetaDto(
                current_page: $currentPage,
                has_next_page: $hasNextPage,
                total_items: 0, // Можно распарсить "Найдено результатов", если нужно
                items_per_page: 10,
                next_page_params: $nextPageParams
            )
        );
    }

    protected function parseAttachments(string $commonInfoUrl): array
    {
        $docsUrl = str_replace('common-info.html', 'documents.html', $commonInfoUrl);

        $page = $this->browser->openPage($docsUrl);
        // Обязательная задержка для AJAX отрисовки списка файлов
        usleep(2000000);

        $html = $page->getHtml();
        $dom = new DomCrawler($html);

        $attachments = $dom->filter('.attachment a[href*="download"], a[href*="filestore"]')->each(function (DomCrawler $node) {
            $href = $node->attr('href');
            if (!$href || str_contains($href, 'signview') || str_contains($href, 'javascript')) return null;

            $fullUrl = str_starts_with($href, 'http') ? $href : 'https://zakupki.gov.ru' . $href;
            parse_str(parse_url($fullUrl, PHP_URL_QUERY) ?? '', $query);

            // UID — для ссылок filestore (44-ФЗ), id — для старого интерфейса
            $externalId = $query['uid'] ?? ($query['id'] ?? md5($href));

            return new AttachmentDto(
                externalId: (string) $externalId,
                url: $fullUrl,
                name: preg_replace('/\s+/', ' ', trim($node->attr('title') ?: $node->text()))
            );
        });

        $unique = array_values(array_filter(array_unique($attachments, SORT_REGULAR)));

        if (empty($unique)) {
            // В случае ошибки сохраняем HTML для дебага
            if (app()->environment('local')) {
                \Illuminate\Support\Facades\Storage::put('debug_eis_empty.html', $html);
            }
            throw new RuntimeException("Документы не найдены для $docsUrl");
        }

        return $unique;
    }

    private function parseDate(DomCrawler $node, string $label): ?Carbon
    {
        $dateNode = $node->filter(".data-block__title:contains('$label') + .data-block__value");
        return $dateNode->count() ? Carbon::parse(trim($dateNode->text())) : null;
    }

    protected function filter(CrawlingItemDto $item): bool|string
    {
        // Расширенный фильтр (питание + ПО)
        $keywords = ['питание', 'программное', 'лиценз'];
        foreach ($keywords as $word) {
            if (mb_stripos($item->title, $word) !== false) return true;
        }

        return 'Не соответствует ключевым словам';
    }
}
