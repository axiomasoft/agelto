<?php

declare(strict_types=1);

namespace Platform\Process\Crawling;

use App\Enums\LogOutput;
use App\Services\AppLogger;
use App\Services\BrowserService;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Platform\Dto\Attachments\AttachmentDto;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\LinkDto;
use Platform\Dto\Crawling\PageMetaDto;
use Platform\Dto\Crawling\PageResultDto;
use Platform\Interfaces\Crawling\PlatformCrawling;
use Platform\Models\Crawling\Crawler as CrawlerModel;
use Platform\Models\Crawling\IgnoreLinks;
use Platform\Models\Platform;
use Platform\Repositories\Crawling\CrawlingRepository;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Throwable;

abstract class AbstractCrawlingService implements PlatformCrawling
{
    protected ?CrawlerModel $crawler = null;

    public function __construct(
        protected readonly AppLogger $logger,
        protected readonly BrowserService $browser,
    ) {}

    abstract public function getNodes(string $html): array;

    abstract public function crawlingItem(mixed $node): CrawlingItemDto;

    abstract public function getPageMeta(string $html, int $requestedPage = 1): PageMetaDto;

    abstract protected function parseAttachments(string $commonInfoUrl): array;

    final public function execute(CrawlerModel $crawler): void
    {
        try {
            $this->checkVpnConstraint();

            $this->logger->info('Инициализация браузера и прокси...', LogOutput::Console);
            $this->browser->withRandomProxy();

            $this->run($crawler);

            $this->browser->reportSuccess();
        } catch (Throwable $e) {
            $this->logger->error('Ошибка краулера ' . static::class . ': ' . $e->getMessage());
            $this->browser->reportFail($e);
            throw $e;
        } finally {
            $this->browser->close();
        }
    }

    final public function run(CrawlerModel $crawler): void
    {
        $this->crawler = $crawler;
        $this->crawler->load('platform');

        $baseUrl = $crawler->url;
        $options = $crawler->options ?? [];
        $isFirstPage = true;
        $nextPageParams = [];
        $requestedPage = 1;

        $resumeUrl = null;
        if (! empty($options['keep_page_on_crash']) && ! empty($options['current_url'])) {
            $resumeUrl = $options['current_url'];
            $parsed = parse_url($resumeUrl);
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query);
                $requestedPage = (int) ($query['pageNumber'] ?? 1);
            }
            $isFirstPage = false;
            $nextPageParams = [];
        }

        do {
            $overrideUrl = $resumeUrl;
            $resumeUrl = null;

            $requestUrl = $this->buildRequestUrl($baseUrl, $isFirstPage, $nextPageParams, $overrideUrl);
            $response = $this->fetchPageResponse($requestUrl);

            $pageResult = $this->pageProcessing($response->body(), $requestedPage);

            if (! empty($options['keep_page_on_crash'])) {
                $options['current_url'] = $requestUrl;
                $this->crawler->update(['options' => $options]);
            }

            if (! $pageResult->meta->has_next_page) {
                $this->logger->info('Пагинация завершена (кнопка "Далее" не найдена).', LogOutput::Console);
                break;
            }

            $isFirstPage = false;
            $nextPageParams = $pageResult->meta->next_page_params;
            $requestedPage = (int) ($nextPageParams['pageNumber'] ?? 1);

            $this->logger->info("Готовим переход на страницу {$requestedPage}", LogOutput::Console);
            usleep(mt_rand(2000000, 4000000));
        } while (true);
    }

    final public function getPlatform(): Platform
    {
        return $this->getCrawler()->platform;
    }

    protected function checkVpnConstraint(): void
    {
        /* if (App::environment('local')) {
            $this->logger->info("Проверка на отсутствие VPN...", LogOutput::Console);

            $realIp = env('MY_REAL_IP');
            if (!$realIp) {
                $this->logger->info("[WARNING] MY_REAL_IP не задан. Пропуск проверки.", LogOutput::Console);
                return;
            }

            try {
                $currentIp = trim(Http::timeout(5)->get('https://api.ipify.org')->body());
                if ($currentIp !== (string)$realIp) {
                    throw new RuntimeException("VPN активен ($currentIp). Отключите его.");
                }
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException) throw $e;
                $this->logger->info("[WARNING] Ошибка проверки IP: " . $e->getMessage(), LogOutput::Console);
            }
        } */
    }

    protected function buildRequestUrl(string $url, bool $isFirstPage, array $params, ?string $overrideUrl = null): string
    {
        if ($overrideUrl !== null) {
            return $overrideUrl;
        }

        if ($isFirstPage || empty($params)) {
            return $url;
        }

        $parsedUrl = parse_url($url);
        $query = [];

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }

        $query = array_merge($query, $params);

        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host = $parsedUrl['host'] ?? '';
        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $path = $parsedUrl['path'] ?? '';

        return $scheme . $host . $port . $path . '?' . http_build_query($query);
    }

    protected function fetchPageResponse(string $requestUrl): \Illuminate\Http\Client\Response
    {
        $this->logger->info('Загрузка страницы: ' . $requestUrl, LogOutput::Console);
        $page = $this->browser->openPage($requestUrl);

        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(200, [], $page->getHtml())
        );
    }

    /**
     * Обработка всей страницы и подготовка данных для пагинации.
     *
     * @param  int  $requestedPage  Номер запрошенной страницы (форма в HTML ЕИС часто содержит pageNumber=1, поэтому передаём явно)
     */
    protected function pageProcessing(string $html, int $requestedPage = 1): PageResultDto
    {
        $nodes = $this->getNodes($html);
        $items = [];

        foreach ($nodes as $node) {
            $item = $this->crawlingItem($node);

            $link = $this->crawlingRepository()->findLinkByPlatformAndRegNumber(
                $this->getPlatform()->id,
                $item->externalId
            );

            $needsDocuments = $link === null
                || $link->platform_updated_at === null
                || $item->updatedAt === null
                || $link->platform_updated_at->toDateString() !== $item->updatedAt->toDateString();

            if ($needsDocuments) {
                $this->logger->info("Парсинг вложений для {$item->externalId}", LogOutput::Console);
                $attachmentsData = $this->parseAttachments($item->url);
                $item->attachments = AttachmentDto::collect($attachmentsData, DataCollection::class);
            } else {
                /* $link->load('attachments');
                $item->attachments = AttachmentDto::collect(
                    $link->attachments->map(fn(LinkAttachment $a): AttachmentDto => new AttachmentDto(
                        externalId: $a->external_id,
                        url: $a->url,
                        name: $a->name
                    ))->all(),
                    DataCollection::class
                ); */
            }

            $this->processSingleItem($item);

            $items[] = $item;
        }

        $meta = $this->getPageMeta($html, $requestedPage);

        return new PageResultDto(
            items: CrawlingItemDto::collect($items, DataCollection::class),
            meta: $meta,
        );
    }

    protected function processSingleItem(CrawlingItemDto $item): void
    {
        if ($this->isAlreadyIgnored($item->externalId)) {
            return;
        }

        $ignore = $this->ignore($item);
        if ($ignore !== false) {
            $this->markAsIgnored($item, is_string($ignore) ? $ignore : 'Ignore by logic');

            return;
        }

        $this->handle($item);
    }

    protected function handle(CrawlingItemDto $item): void
    {
        $linkDto = new LinkDto(
            regNumber: $item->externalId,
            detailUrl: $item->url,
            updatedAt: $item->updatedAt,
            publishedAt: $item->publishedAt ?? Carbon::now(),
            submissionEndAt: $item->submissionEndAt,
            attachments: $item->attachments,
        );
        $this->crawlingRepository()->storeOrUpdateLink($this->getCrawler(), $linkDto);
    }

    protected function getCrawler(): CrawlerModel
    {
        return $this->crawler ?? throw new RuntimeException('Crawler is not set.');
    }

    protected function crawlingRepository(): CrawlingRepository
    {
        return app(CrawlingRepository::class);
    }

    protected function isAlreadyIgnored(string $externalId): bool
    {
        return IgnoreLinks::query()
            ->where('platform_id', $this->getPlatform()->id)
            ->where('external_id', $externalId)
            ->exists();
    }

    protected function markAsIgnored(CrawlingItemDto $item, string $reason): void
    {
        IgnoreLinks::create([
            'platform_id' => $this->getPlatform()->id,
            'external_id' => $item->externalId,
            'reason' => $reason,
        ]);
    }

    protected function ignore(CrawlingItemDto $item): bool|string
    {
        $rules = config('platform.estimate.ignore_rules', []);

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? 'title';
            $name = (string) ($rule['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = (string) data_get($item, $field);
            if (mb_stripos($value, $name) !== false) {
                return $rule['name'];
            }
        }

        return false;
    }
}
