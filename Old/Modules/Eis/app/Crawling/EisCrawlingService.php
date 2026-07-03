<?php

declare(strict_types=1);

namespace Eis\Crawling;

use App\Dto\Attachments\AttachmentDto;
use App\Dto\Browser\BrowserOptions;
use App\Dto\Crawling\CrawlingItemDto;
use App\Dto\Crawling\PageMetaDto;
use App\Models\Crawler\Crawler as CrawlerModel;
use App\Process\Crawling\AbstractCrawlingService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Storage;
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
    public function startHook(string $url): void
    {
        // Если мы уже внутри процесса дробления (вызваны из run), выходим
        if ($this->isSplitProcess) {
            return;
        }

        $filters = $this->crawler->filter ?? [];

        if (! empty($filters['publishDateFrom']) && ! empty($filters['publishDateTo'])) {
            $this->archiveCrawl($url, Carbon::parse($filters['publishDateFrom']), Carbon::parse($filters['publishDateTo']));

            return;
        }

        // Оперативный режим: свежие данные сортируются по обновлению, страницы быстро «съезжают»,
        // поэтому сохранённый курсор неактуален — всегда полный проход с нуля.
        if ($this->run !== null) {
            $this->runManager->resetCursor($this->run);
        }

        $this->logger->event('ЕИС [Оперативный режим]: полный проход по свежим данным.');
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
        return (new DomCrawler($html))->filter('.search-registry-entry-block')->each(fn (DomCrawler $node) => $node);
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        $externalId = mb_trim($node->filter('.registry-entry__header-mid__number')->text());
        $subject = mb_trim($node->filter('.registry-entry__body-value')->text());
        $url = 'https://zakupki.gov.ru'.$node->filter('.registry-entry__header-mid__number a')->attr('href');
        $priceText = $node->filter('.price-block__value')->count() ? $node->filter('.price-block__value')->text() : '0';
        $price = (float) preg_replace('/[^\d.]/', '', str_replace(',', '.', $priceText));

        return new CrawlingItemDto(
            externalId: $externalId,
            url: $url,
            subject: $subject,
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
            $fullUrl = str_starts_with($href, 'http') ? $href : 'https://zakupki.gov.ru'.$href;
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
     * Архивный режим: обход по дням. Прошедшие дни стабильны, поэтому возобновление с
     * незавершённого дня дёшево и корректно (страницы внутри дня не «съезжают»).
     */
    private function archiveCrawl(string $url, Carbon $dateFrom, Carbon $dateTo): void
    {
        $resumeDay = $this->resumeDay($dateFrom, $dateTo);
        if ($resumeDay !== null) {
            $dateFrom = $resumeDay;
            $this->logger->event("ЕИС [Архивный режим]: возобновление с дня {$dateFrom->format('Y-m-d')}.");
        }

        // Если даты одинаковые, значит мы уже "внизу" цикла по дням
        if ($dateFrom->isSameDay($dateTo)) {
            $this->rememberDay($dateFrom, $dateTo);
            $this->priceSplitHook($url);

            return;
        }

        $period = CarbonPeriod::create($dateFrom, $dateTo);
        $this->logger->event("ЕИС [Архивный режим]: период {$dateFrom->format('d.m.Y')} — {$dateTo->format('d.m.Y')}.");

        foreach ($period as $date) {
            $this->logger->info('ЕИС: обработка дня '.$date->format('d.m.Y'));
            $this->rememberDay($date, $dateTo);

            $dayUrl = (string) Url::fromString($url)
                ->withQueryParameter('publishDateFrom', $date->format('d.m.Y'))
                ->withQueryParameter('publishDateTo', $date->format('d.m.Y'));

            $this->priceSplitHook($dayUrl);
        }
    }

    /**
     * День возобновления: сначала из чекпойнта прогона (plt_crawler_runs.day_from),
     * затем — из legacy Crawler.state. Null — если возобновлять не с чего или день вне окна.
     */
    private function resumeDay(Carbon $dateFrom, Carbon $dateTo): ?Carbon
    {
        $candidate = $this->run?->day_from;

        if ($candidate === null && $this->keepStateOnCrash('current_day')) {
            $candidate = Carbon::parse($this->crawler->state['current_day']);
        }

        if ($candidate === null) {
            return null;
        }

        $candidate = $candidate instanceof Carbon ? $candidate : Carbon::parse($candidate);

        if ($candidate->lt($dateFrom) || $candidate->gt($dateTo)) {
            return null;
        }

        return $candidate;
    }

    /** Фиксирует текущий день и в чекпойнте прогона, и в legacy Crawler.state. */
    private function rememberDay(Carbon $day, Carbon $dateTo): void
    {
        if ($this->run !== null) {
            $this->runManager->heartbeat($this->run, [
                'day_from' => $day->toDateString(),
                'day_to' => $dateTo->toDateString(),
            ]);
        }

        if ($this->keepStateOnCrash()) {
            $this->crawler->updateState(['current_day' => $day->format('Y-m-d')]);
        }
    }

    /**
     * Рекурсивное дробление по ценам.
     */
    private function priceSplitHook(string $url): void
    {
        // Выводим ссылку в консоль для ручной проверки
        $this->logger->debug('ЕИС Ссылка для проверки: '.$url);

        $page = $this->browser->openPage($url, new BrowserOptions(
            retries: 3,
            rotate_proxy_on_fail: true,
        ));

        $html = $page->getHtml();
        $totalRecords = $this->extractTotalRecords($html);

        // Если записей 0, сразу выходим
        if ($totalRecords === 0) {
            $this->logger->info('ЕИС: Результатов не найдено (0 записей).');

            return;
        }

        $urlObject = Url::fromString($url);

        // Если записей больше лимита (1000), нужно дробить
        if ($totalRecords > self::MAX_RESULTS) {
            $priceFrom = (float) ($urlObject->getQueryParameter('priceFromGeneral') ?? 0);
            $priceTo = (float) ($urlObject->getQueryParameter('priceToGeneral') ?? 1000000000);
            $diff = $priceTo - $priceFrom;

            if ($diff < 10000) {
                $this->logger->error("!!! СТОП РЕКУРСИЯ: Дистанция цены слишком мала ($diff руб) !!!");
                $this->logger->info("ЕИС: Забираем доступные 1000 записей из $totalRecords найденных.");

                $this->processAllIntervalPages($url);

                return;
            }

            // Логика дробления...
            $middle = round($priceFrom + ($diff / 2), 2);
            $this->logger->warning("ЕИС: лимит превышен ($totalRecords зап.). Дробление цен: $priceFrom - $priceTo");

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

    private function extractTotalRecords(string $html): int
    {
        $dom = new DomCrawler($html);
        $totalNode = $dom->filter('.search-results__total');

        if ($totalNode->count() > 0) {
            $text = $totalNode->text();
            // Убираем неразрывные пробелы и лишние символы
            $cleanText = str_replace('&nbsp;', '', $text);
            $cleanText = preg_replace('/\s+/', '', $cleanText);

            // Извлекаем все цифры из строки (например, из "более7800записей" получим 7800)
            if (preg_match_all('/\d+/', $cleanText, $matches)) {
                return (int) implode('', $matches[0]);
            }
        }

        return 0;
    }

    private function parseDate(DomCrawler $node, string $label): ?Carbon
    {
        $dateNode = $node->filter(".data-block__title:contains('$label') + .data-block__value");

        return $dateNode->count() ? Carbon::parse(mb_trim($dateNode->text())) : null;
    }
}
