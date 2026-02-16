<?php

declare(strict_types=1);

namespace Eis\Crawling;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Platform\Dto\Attachments\AttachmentDto;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Models\Crawling\Crawler as CrawlerModel;
use Platform\Process\Crawling\AbstractCrawlingService;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

final class EisCrawlingService extends AbstractCrawlingService
{
    /**
     * Лимит поисковой выдачи ЕИС (после 1000 результатов записи не отображаются)
     */
    private const MAX_RESULTS = 1000;

    /**
     * Продвинутый метод входа: запускает рекурсивное дробление по ценам,
     * чтобы обойти ограничение ЕИС в 1000 записей.
     */
    public function parseDeep(CrawlerModel $crawler, ?array $manualFilters = null): void
    {
        $filters = $manualFilters ?? $crawler->filter ?? [];
        $url = $this->getCrawlerUrl($crawler, $filters);

        $this->logger->info('ЕИС: глубокое сканирование: ' . $url);

        $page = $this->browser->openPage($url);
        $html = $page->getHtml();

        // Получаем макс. страницу из пагинации HTML
        $maxPage = $this->extractMaxPageFromHtml($html);

        // Динамический расчет лимита страниц (например, 1000 / 50 = 20 страниц)
        $perPageValue = (int) str_replace('_', '', (string) ($filters[$this->recordsPerPageKey($crawler)] ?? '50'));
        $limitPage = (int) ceil(self::MAX_RESULTS / $perPageValue);

        // Если достигнут предел выдачи ЕИС, дробнм интервал цен
        if ($maxPage >= $limitPage) {
            $priceFrom = (float) ($filters['priceFromGeneral'] ?? 0);
            $priceTo = (float) ($filters['priceToGeneral'] ?? 1000000000);

            // Если разница в цене больше 1 копейки, дробим
            if (($priceTo - $priceFrom) > 0.01) {
                $middle = round(($priceFrom + $priceTo) / 2, 2);

                $this->logger->warning('ЕИС: лимит результатов (' . self::MAX_RESULTS . ") достигнут на странице $maxPage. Дробление интервала: $priceFrom - $priceTo");

                // Рекурсия влево
                $this->parseDeep($crawler, array_merge($filters, ['priceToGeneral' => $middle]));

                // Рекурсия вправо
                $this->parseDeep($crawler, array_merge($filters, ['priceFromGeneral' => $middle + 0.01]));

                return;
            }
        }

        // Если лимит не превышен, запускаем стандартный процесс парсинга страниц
        $this->processAllIntervalPages($crawler, $filters);
    }

    public function getNodes(string $html): array
    {
        return (new DomCrawler($html))->filter('.search-registry-entry-block')->each(fn(DomCrawler $node) => $node);
    }

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

    /**
     * Формирование URL. Учтены fz=on и формат дат d.m.Y.
     */
    public function getCrawlerUrl(CrawlerModel $crawler, ?array $overrideFilters = null): string
    {
        $url = $crawler->url ?? '';
        $filters = $overrideFilters ?? $crawler->filter ?? [];

        $queryParams = [
            'morphology' => 'on',
            'currencyIdGeneral' => '-1',
        ];

        // ФЗ: проставляем fzXX=on
        if (! empty($filters['fz']) && is_array($filters['fz'])) {
            foreach ($filters['fz'] as $fzKey) {
                $queryParams[$fzKey] = 'on';
            }
        }

        // Даты: конвертация в d.m.Y для ЕИС
        $dateFields = ['publishDateFrom', 'publishDateTo', 'applSubmissionCloseDateFrom', 'applSubmissionCloseDateTo'];
        foreach ($dateFields as $field) {
            if (! empty($filters[$field])) {
                $queryParams[$field] = Carbon::parse($filters[$field])->format('d.m.Y');
            }
        }

        // Прочие поля фильтрации
        $simpleFields = ['sortBy', 'sortDirection', 'priceFromGeneral', 'priceToGeneral'];
        foreach ($simpleFields as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $queryParams[$field] = $filters[$field];
            }
        }

        // recordsPerPage по аналогии с pageNumberKey
        $recordsKey = $this->recordsPerPageKey($crawler);
        if (isset($filters[$recordsKey])) {
            $queryParams[$recordsKey] = $filters[$recordsKey];
        }

        // pageNumberKey
        $pageKey = $this->pageNumberKey($crawler);
        if (isset($filters[$pageKey])) {
            $queryParams[$pageKey] = $filters[$pageKey];
        }

        $parsed = parse_url($url) ?: [];
        parse_str($parsed['query'] ?? '', $existing);
        $merged = array_merge($existing, $queryParams);

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : 'https://';
        $host = $parsed['host'] ?? 'zakupki.gov.ru';
        $path = $parsed['path'] ?? '/epz/order/docSearch/results.html';

        return $scheme . $host . $path . '?' . http_build_query($merged);
    }

    public function pageNumberKey(CrawlerModel $crawler): string
    {
        return 'pageNumber';
    }

    /**
     * Ключ для количества записей на страницу (по аналогии с pageNumberKey)
     */
    public function recordsPerPageKey(CrawlerModel $crawler): string
    {
        return 'recordsPerPage';
    }

    public function getPageMeta(string $html): PageMetaDto
    {
        $dom = new DomCrawler($html);
        $hasNextPage = $dom->filter('.paginator .paginator-button-next')->count() > 0;

        // Используем recordsPerPageKey для получения значения из фильтра
        $recordsKey = $this->recordsPerPageKey($this->crawler);
        $perPageValue = $this->crawler?->filter[$recordsKey] ?? '50';
        $perPage = (int) str_replace('_', '', (string) $perPageValue);

        return new PageMetaDto(
            current_page: 1,
            has_next_page: $hasNextPage,
            total_items: 0,
            items_per_page: $perPage,
        );
    }

    /**
     * Сбор документов с вкладки "Документы" закупки.
     */
    protected function parseAttachments(string $commonInfoUrl): array
    {
        $docsUrl = str_replace('common-info.html', 'documents.html', $commonInfoUrl);
        $page = $this->browser->openPage($docsUrl);
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
            $externalId = $query['uid'] ?? ($query['id'] ?? md5($href));

            return new AttachmentDto(
                externalId: (string) $externalId,
                url: $fullUrl,
                name: preg_replace('/\s+/', ' ', mb_trim($node->attr('title') ?: $node->text()))
            );
        });

        $unique = array_values(array_filter(array_unique($attachments, SORT_REGULAR)));

        if (empty($unique)) {
            // Если документов нет, это подозрительно для ЕИС, сохраняем для отладки
            if (app()->environment('local')) {
                Storage::put('debug_eis_empty_docs.html', $html);
            }
            throw new RuntimeException("Документы не найдены для закупки: $docsUrl");
        }

        return $unique;
    }

    /**
     * Синхронизация с AbstractCrawlingService.
     */
    private function processAllIntervalPages(CrawlerModel $crawler, array $filters): void
    {
        $originalFilters = $crawler->filter;
        $crawler->filter = $filters;

        try {
            $this->crawlingPage($this->getCrawlerUrl($crawler));
        } finally {
            $crawler->filter = $originalFilters;
        }
    }

    private function extractMaxPageFromHtml(string $html): int
    {
        $dom = new DomCrawler($html);
        $paginator = $dom->filter('.paginator .page');
        if ($paginator->count() > 0) {
            return (int) preg_replace('/\D/', '', $paginator->last()->text());
        }

        return 1;
    }

    private function parseDate(DomCrawler $node, string $label): ?Carbon
    {
        $dateNode = $node->filter(".data-block__title:contains('$label') + .data-block__value");

        return $dateNode->count() ? Carbon::parse(mb_trim($dateNode->text())) : null;
    }
}
