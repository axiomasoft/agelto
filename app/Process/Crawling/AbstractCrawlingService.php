<?php

declare(strict_types=1);

namespace App\Process\Crawling;

use App\Dto\Attachments\AttachmentDto;
use App\Dto\Browser\BrowserOptions;
use App\Dto\Crawling\CrawlingItemDto;
use App\Dto\Crawling\LinkDto;
use App\Dto\Crawling\PageMetaDto;
use App\Dto\Crawling\PageResultDto;
use App\Enums\LogOutput;
use App\Interfaces\Crawling\PlatformCrawling;
use App\Models\Crawler\Crawler as CrawlerModel;
use App\Models\Crawler\IgnoredLink;
use App\Models\Platform;
use App\Repositories\Crawling\CrawlingRepository;
use App\Services\AppLogger;
use App\Services\BrowserService;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
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

    abstract public function getPageMeta(string $html): PageMetaDto;

    abstract public function getCrawlerUrl(CrawlerModel $crawler): string;

    abstract public function pageNumberKey(CrawlerModel $crawler): string;

    abstract protected function parseAttachments(string $commonInfoUrl): array;

    final public function start(CrawlerModel $crawler): void
    {
        try {
            $this->checkVpnConstraint();

            $this->logger->info('Инициализация браузера и прокси...', LogOutput::Console);
            $this->browser->withRandomProxy();

            $this->crawler = $crawler;
            $this->crawler->load('platform');

            $url = $this->getCrawlerUrl($crawler);

            // если браузер падает, то продолжаем с последней страницы (если включен keep_state_on_crash у краулера)
            if ($this->keepStateOnCrash('current_url')) {
                $url = $this->crawler->state['current_url'];
                $this->logger->info('Восстановление с URL: '.$url, LogOutput::Console);
            }

            $this->startHook($url);

            $this->browser->reportSuccess();
        } catch (Throwable $e) {
            $this->logger->error('Ошибка краулера '.static::class.': '.$e->getMessage());
            $this->browser->reportFail($e);
            throw $e;
        } finally {
            $this->browser->close();
        }
    }

    final public function crawlingPage(string $url): void
    {
        $pageKey = $this->pageNumberKey($this->crawler);
        $urlObject = \Spatie\Url\Url::fromString($url);

        do {
            $currentPage = (int) $urlObject->getQueryParameter($pageKey, 1);
            $requestUrl = (string) $urlObject;

            if ($this->keepStateOnCrash()) {
                $this->crawler->updateState(['current_url' => $requestUrl]);
            }

            $response = $this->fetchPage($requestUrl);

            $pageResult = $this->pageProcessing($response->body());

            if (! $pageResult->meta->has_next_page) {
                $this->logger->info('Пагинация завершена (кнопка "Далее" не найдена).', LogOutput::Console);
                break;
            }

            $nextPage = (string) ($currentPage + 1);
            $urlObject = $urlObject->withQueryParameter($pageKey, $nextPage);

            $this->logger->info("Готовим переход на страницу {$nextPage}", LogOutput::Console);
            usleep(mt_rand(3_000_000, 5_000_000));
        } while (true);
    }

    final public function getPlatform(): Platform
    {
        return $this->getCrawler()->platform;
    }

    protected function startHook(string $url): void
    {
        $this->crawlingPage($url);
    }

    protected function keepStateOnCrash(?string $storeKey = null): bool
    {
        $options = $this->crawler->options ?? [];
        $state = $this->crawler->state ?? [];

        // 1. Проверяем, включена ли сама фича
        $isEnabled = ! empty($options['keep_state_on_crash']);

        // 2. Если фича включена и storeKey не передан — значит проверяем просто общую готовность
        if ($storeKey === null) {
            return $isEnabled;
        }

        // 3. Если ключ передан, проверяем, есть ли в опциях или в самой модели данные по этому ключу
        // (Например, проверяем, заполнено ли поле last_url в модели или в options)
        return $isEnabled && ! empty($state[$storeKey]);
    }

    protected function checkVpnConstraint(): void
    {
        // Проверка VPN отключена. Раньше тут сверяли текущий IP с config('app.my_real_ip').
    }

    protected function fetchPage(string $requestUrl): \Illuminate\Http\Client\Response
    {
        $this->logger->info('Загрузка страницы: '.$requestUrl, LogOutput::Console);
        $page = $this->browser->openPage($requestUrl, new BrowserOptions(
            retries: 3,
            rotate_proxy_on_fail: true,
        ));

        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(200, [], $page->getHtml())
        );
    }

    /**
     * Обработка всей страницы и подготовка данных для пагинации.
     *
     * @param  int  $requestedPage  Номер запрошенной страницы (ключ пагинации задаётся через pageNumberKey())
     */
    protected function pageProcessing(string $html): PageResultDto
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
                $this->logger->warning("пропускаем Парсинг вложений для {$item->externalId}", LogOutput::Console);
            }

            /* $link->load('attachments');
            $item->attachments = AttachmentDto::collect(
                $link->attachments->map(fn(LinkAttachment $a): AttachmentDto => new AttachmentDto(
                    externalId: $a->external_id,
                    url: $a->url,
                    name: $a->name
                ))->all(),
                DataCollection::class
            ); */

            $this->processSingleItem($item);

            $items[] = $item;
        }

        $meta = $this->getPageMeta($html);

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
        return IgnoredLink::query()
            ->where('platform_id', $this->getPlatform()->id)
            ->where('external_id', $externalId)
            ->exists();
    }

    protected function markAsIgnored(CrawlingItemDto $item, string $reason): void
    {
        IgnoredLink::create([
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
