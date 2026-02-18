<?php

declare(strict_types=1);

namespace Eis\Crawling;

use App\Dto\Browser\BrowserOptions;
use App\Enums\LogOutput;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Storage;
use Platform\Dto\Attachments\AttachmentDto;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Models\Crawling\Crawler as CrawlerModel;
use Platform\Process\Crawling\AbstractCrawlingService;
use RuntimeException;
use Spatie\Url\Url;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

final class EisCrawlingService extends AbstractCrawlingService
{
    private const MAX_RESULTS = 1000;

    /**
     * Флаг для предотвращения бесконечной рекурсии при вызове run()
     */
    private bool $isSplitProcess = false;

    /**
     * Точка входа.
     */
    protected function startHook(string $url): void
    {
        // Если мы уже внутри процесса дробления (вызваны из run), выходим
        if ($this->isSplitProcess) {
            return;
        }

        $filters = $this->crawler->filter ?? [];

        if (! empty($filters['publishDateFrom']) && ! empty($filters['publishDateTo'])) {
            $dateFrom = Carbon::parse($filters['publishDateFrom']);
            $dateTo = Carbon::parse($filters['publishDateTo']);

            if ($this->keepStateOnCrash('current_day')) {
                $dateFrom = Carbon::parse($this->crawler->options['current_day']);
            }

            // Если даты одинаковые, значит мы уже "внизу" цикла по дням
            if ($dateFrom->isSameDay($dateTo)) {
                $this->priceSplitHook($url);

                return;
            }

            $period = CarbonPeriod::create($dateFrom, $dateTo);
            $this->logger->info("ЕИС [Архивный режим]: Период {$dateFrom->format('d.m.Y')} - {$dateTo->format('d.m.Y')}");

            foreach ($period as $date) {
                $currentDay = $date->format('d.m.Y');
                $this->logger->info("ЕИС: Обработка дня: $currentDay");

                // Сохраняем состояние парсера, если включена фича keep_state_on_crash
                if ($this->keepStateOnCrash()) {
                    $this->crawler->updateOptions([
                        'current_day' => $date->format('Y-m-d'),
                    ]);
                    $this->logger->info("Сохраняем состояние парсера {$currentDay}", LogOutput::Console);
                }

                $dayUrl = (string) Url::fromString($url)
                    ->withQueryParameter('publishDateFrom', $currentDay)
                    ->withQueryParameter('publishDateTo', $currentDay);

                $this->priceSplitHook($dayUrl);
            }

            return;
        }

        $this->logger->info('ЕИС [Оперативный режим]: Анализ свежих данных');
        $this->priceSplitHook($url);
    }

    public function getCrawlerUrl(CrawlerModel $crawler): string
    {
        $filters = $crawler->filter ?? [];
        $queryParams = ['morphology' => 'on', 'currencyIdGeneral' => '-1'];

        if (! empty($filters['fz']) && is_array($filters['fz'])) {
            foreach ($filters['fz'] as $fzKey) {
                $queryParams[$fzKey] = 'on';
            }
        }

        $dateFields = ['publishDateFrom', 'publishDateTo', 'applSubmissionCloseDateFrom', 'applSubmissionCloseDateTo'];
        foreach ($dateFields as $field) {
            if (! empty($filters[$field])) {
                $queryParams[$field] = Carbon::parse($filters[$field])->format('d.m.Y');
            }
        }

        foreach (['sortBy', 'sortDirection', 'priceFromGeneral', 'priceToGeneral'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $queryParams[$field] = $filters[$field];
            }
        }

        $queryParams[$this->recordsPerPageKey($crawler)] = $filters[$this->recordsPerPageKey($crawler)] ?? '50';

        $pageKey = $this->pageNumberKey($crawler);
        if (isset($filters[$pageKey])) {
            $queryParams[$pageKey] = $filters[$pageKey];
        }

        return (string) Url::fromString($crawler->url)->withQueryParameters($queryParams);
    }

    public function pageNumberKey(CrawlerModel $crawler): string
    {
        return 'pageNumber';
    }

    public function recordsPerPageKey(CrawlerModel $crawler): string
    {
        return 'recordsPerPage';
    }

    public function getNodes(string $html): array
    {
        return (new DomCrawler($html))->filter('.search-registry-entry-block')->each(fn(DomCrawler $node) => $node);
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
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

    public function getPageMeta(string $html): PageMetaDto
    {
        $dom = new DomCrawler($html);
        $hasNextPage = $dom->filter('.paginator .paginator-button-next')->count() > 0;
        $recordsKey = $this->recordsPerPageKey($this->crawler);
        $perPageValue = $this->crawler?->filter[$recordsKey] ?? '50';
        $perPage = (int) str_replace('_', '', (string) $perPageValue);

        return new PageMetaDto(current_page: 0, has_next_page: $hasNextPage, total_items: 0, items_per_page: $perPage);
    }

    protected function parseAttachments(string $commonInfoUrl): array
    {
        $docsUrl = str_replace('common-info.html', 'documents.html', $commonInfoUrl);
        $page = $this->browser->openPage($docsUrl, new BrowserOptions(
            retries: 3,
            rotate_proxy_on_fail: true,
        ));

        // Ожидание для предотвращения блокировки (3 секунды)
        usleep(3_000_000);

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

            return new AttachmentDto(externalId: (string) $externalId, url: $fullUrl, name: preg_replace('/\s+/', ' ', mb_trim($node->attr('title') ?: $node->text())));
        });

        $unique = array_values(array_filter(array_unique($attachments, SORT_REGULAR)));
        if (empty($unique)) {
            if (app()->environment('local')) {
                Storage::put('debug_eis_empty_docs.html', $html);
            }
            throw new RuntimeException("Документы не найдены для закупки: $docsUrl");
        }

        return $unique;
    }

    /**
     * Рекурсивное дробление по ценам.
     */
    private function priceSplitHook(string $url): void
    {
        // Выводим ссылку в консоль для ручной проверки
        $this->logger->info('ЕИС Ссылка для проверки: ' . $url);

        $page = $this->browser->openPage($url, new BrowserOptions(
            retries: 3,
            rotate_proxy_on_fail: true,
        ));

        $html = $page->getHtml();
        $maxPage = $this->extractMaxPageFromHtml($html);

        $urlObject = Url::fromString($url);
        $filters = $this->crawler->filter ?? [];

        $recordsKey = $this->recordsPerPageKey($this->crawler);
        $perPageValue = (int) str_replace('_', '', (string) ($filters[$recordsKey] ?? '50'));
        $limitPage = (int) ceil(self::MAX_RESULTS / $perPageValue);

        if ($maxPage >= $limitPage) {
            $priceFrom = (float) ($urlObject->getQueryParameter('priceFromGeneral') ?? 0);
            $priceTo = (float) ($urlObject->getQueryParameter('priceToGeneral') ?? 1000000000);
            $diff = $priceTo - $priceFrom;

            if ($diff < 10000) {
                $this->logger->error("!!! СТОП РЕКУРСИЯ: Дистанция цены слишком мала ($diff руб) !!!");
                $this->logger->info("ЕИС: Парсим доступные {$maxPage} страниц для диапазона {$priceFrom} - {$priceTo}");

                // Включаем режим принудительного парсинга
                $this->processAllIntervalPages($url);

                return;
            }

            // Логика дробления...
            $middle = round($priceFrom + ($diff / 2), 2);
            $this->logger->warning("ЕИС: лимит достигнут ({$maxPage} стр). Дробление цен: $priceFrom - $priceTo");

            $this->priceSplitHook((string) $urlObject->withQueryParameter('priceToGeneral', (string) $middle));
            $this->priceSplitHook((string) $urlObject->withQueryParameter('priceFromGeneral', (string) ($middle + 0.01)));

            return;
        }

        $this->processAllIntervalPages($url);
    }

    private function processAllIntervalPages(string $url): void
    {
        $urlObject = Url::fromString($url);
        parse_str($urlObject->getQuery() ?? '', $queryFilters);

        $originalFilters = $this->crawler->filter;
        $this->crawler->filter = array_merge($originalFilters, $queryFilters);

        // Включаем блокировку рекурсии через startHook
        $this->isSplitProcess = true;

        try {
            $this->crawlingPage($this->getCrawlerUrl($this->crawler));
        } finally {
            // Выключаем блокировку и возвращаем фильтры
            $this->isSplitProcess = false;
            $this->crawler->filter = $originalFilters;
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
