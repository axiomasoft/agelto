<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Crawler\StageSettings;
use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use App\Repositories\Procurement\Platform\Okpd2NodeStoreRepository;
use App\Services\Procurement\CrawlSettingsResolver;
use App\Services\Procurement\PlatformRequestBudget;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\SourceTlsTrust;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Пересборка карты «код ОКПД2 → внутренний id НСИ» обходом `children.html` вширь и сверка
 * снятого с `prc_okpd2_nodes` (P2.5, D14).
 *
 * Почему пересборка И ЕСТЬ детект дрейфа: дешёвого способа спросить площадку «этот id всё ещё
 * код 43?» не существует — единственный ответ, где `code` и `key` приходят ПАРОЙ, это листинг
 * детей родителя (`findings/P1-okpd2-map.md` §6.2). Расхождение хотя бы одной пары —
 * не «поправить строку», а признак перенумерации всего диапазона (§1.1): профили, ссылающиеся
 * на КОДЫ, при этом не страдают, ради чего код и хранится вместо id (D9).
 *
 * Канал к площадке — те же три сейма, что у боевого `CsvSourceMethod`: лиза
 * `ProxyResolver::resolve()` → общий `PlatformRequestBudget` → запрос с `verify` из `SourceTlsTrust`.
 * Второго канала не заводится (гэп G7, Implementation Rules P2.5 п. 1).
 */
final readonly class Okpd2NodeSynchronizer
{
    /**
     * Карта живёт по площадкам, а эндпоинт `children.html` — ЕИС-специфика, поэтому синхронизатор
     * ЕИС знает свою площадку по коду (тот же приём, что `Okpd2NodeSeeder`).
     */
    private const string PLATFORM_CODE = 'eis';

    public function __construct(
        private Okpd2NodeReadRepository $nodes,
        private Okpd2NodeStoreRepository $store,
        private ProxyResolver $proxyResolver,
        private PlatformRequestBudget $requestBudget,
        private CrawlSettingsResolver $crawlSettings,
        private SourceTlsTrust $tlsTrust,
    ) {}

    /**
     * @param  string|null  $root  id узла-корня НСИ; `null` — `config('crawler.okpd2.root_external_id')`
     * @param  int|null  $maxRequests  потолок запросов к площадке; `null` — `config('crawler.okpd2.max_requests')`
     * @return array{
     *     requests: int,
     *     nodes_seen: int,
     *     new: list<array{code: string, external_id: string}>,
     *     missing: list<array{code: string, external_id: string}>,
     *     changed: list<array{code: string, external_id: string, stored_external_id: string}>,
     *     not_traversed: list<string>,
     *     drifted_active_codes: list<string>,
     *     written: bool,
     * }
     */
    public function run(?string $root = null, bool $dryRun = false, ?int $maxRequests = null): array
    {
        $platform = $this->requirePlatform();
        $rootId = $root ?? (string) config('crawler.okpd2.root_external_id');
        $limit = $maxRequests ?? (int) config('crawler.okpd2.max_requests');

        $stored = $this->snapshot(platformId: (int) $platform->id);

        // Нумерацию детей задаёт уровень корня. Сам корень в карту не пишется, поэтому обычно
        // его там нет — тогда уровень берётся из конфига (раздел = 1).
        $rootLevel = (int) config('crawler.okpd2.root_level');

        foreach ($stored as $node) {
            if ($node['external_id'] === $rootId) {
                $rootLevel = $node['level'];
            }
        }

        [$crawled, $expandedCodes, $notTraversed, $requests] = $this->traverse(
            rootId: $rootId,
            rootLevel: $rootLevel,
            limit: $limit,
            platform: $platform,
        );

        $new = [];
        $changed = [];

        foreach ($crawled as $code => $node) {
            $storedNode = $stored[$code] ?? null;

            if ($storedNode === null) {
                $new[] = ['code' => $code, 'external_id' => $node['external_id']];

                continue;
            }

            if ($storedNode['external_id'] !== $node['external_id']) {
                $changed[] = [
                    'code' => $code,
                    'external_id' => $node['external_id'],
                    'stored_external_id' => $storedNode['external_id'],
                ];
            }
        }

        $missing = $this->missingNodes(stored: $stored, crawled: $crawled, expandedCodes: $expandedCodes);

        if (! $dryRun) {
            $syncedAt = now();

            foreach ($crawled as $node) {
                $this->store->storeOrUpdate(platformId: (int) $platform->id, node: $node, syncedAt: $syncedAt);
            }
        }

        $driftedCodes = [
            ...array_column($changed, 'code'),
            ...array_column($missing, 'code'),
        ];

        return [
            'requests' => $requests,
            'nodes_seen' => count($crawled),
            'new' => $new,
            'missing' => $missing,
            'changed' => $changed,
            'not_traversed' => $notTraversed,
            'drifted_active_codes' => $this->activeProfileCodesAmong(
                platformId: (int) $platform->id,
                codes: $driftedCodes,
            ),
            'written' => ! $dryRun,
        ];
    }

    /**
     * Обход вширь от корня. Потолок запросов ЖЁСТКИЙ: достигнут — очередь не дообходится, а
     * остаётся в `not_traversed` (Implementation Rules п. 3), и это единственный честный способ
     * отличить «узла на площадке нет» от «мы до него не дошли».
     *
     * @return array{0: array<string, array{code: string, external_id: string, parent_code: string|null, level: int, name: string}>, 1: list<string|null>, 2: list<string>, 3: int}
     */
    private function traverse(string $rootId, int $rootLevel, int $limit, Platform $platform): array
    {
        $endpoint = (string) config('crawler.okpd2.children_endpoint');
        $lease = $this->proxyResolver->resolve(
            scope: ProxyScope::CrawlListing,
            target: $this->canonicalHost($endpoint),
            requirements: new ProxyRequirements(crawlerMode: CrawlerProxyMode::Default),
        );
        $settings = $this->crawlSettings->forStage(stage: CrawlStage::Listing, platform: $platform);

        // Корень поддерева в карту НЕ пишется: его `parent_code` неизвестен, а дети получают
        // `parent_code = null` — ровно та форма, в которой карту снял и засидировал P1.2/P2.1.
        $queue = [['external_id' => $rootId, 'code' => null, 'level' => $rootLevel]];

        $crawled = [];
        $expandedCodes = [];
        $notTraversed = [];
        $requests = 0;

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($requests >= $limit) {
                $notTraversed[] = $current['code'] ?? $current['external_id'];

                continue;
            }

            $this->requestBudget->acquire(
                host: $this->canonicalHost($endpoint),
                stage: CrawlStage::Listing,
            );
            $children = $this->fetchChildren(lease: $lease, endpoint: $endpoint, parentId: $current['external_id'], settings: $settings);
            $requests++;
            $expandedCodes[] = $current['code'];

            foreach ($children as $child) {
                $node = $this->nodeFrom(child: $child, parent: $current, parentId: $current['external_id']);
                $crawled[$node['code']] = $node;

                if ($child['hasChildren'] ?? false) {
                    $queue[] = [
                        'external_id' => $node['external_id'],
                        'code' => $node['code'],
                        'level' => $node['level'],
                    ];
                }
            }
        }

        return [$crawled, $expandedCodes, $notTraversed, $requests];
    }

    /**
     * Узел считается исчезнувшим ТОЛЬКО если его родителя мы в этом прогоне раскрывали: иначе
     * упёршийся в потолок обход объявил бы «исчезнувшим» всё, до чего не дошёл.
     *
     * @param  array<string, array{external_id: string, parent_code: string|null, level: int}>  $stored
     * @param  array<string, array{code: string, external_id: string, parent_code: string|null, level: int, name: string}>  $crawled
     * @param  list<string|null>  $expandedCodes
     * @return list<array{code: string, external_id: string}>
     */
    private function missingNodes(array $stored, array $crawled, array $expandedCodes): array
    {
        $missing = [];

        foreach ($stored as $code => $node) {
            if (isset($crawled[$code])) {
                continue;
            }

            if (! in_array($node['parent_code'], $expandedCodes, strict: true)) {
                continue;
            }

            $missing[] = ['code' => $code, 'external_id' => $node['external_id']];
        }

        return $missing;
    }

    /**
     * Снимок карты в простых значениях: дальше идёт сверка ПАР (код, id), которой модель не
     * нужна, а `Okpd2Node` не объявляет `@property`-контракта на колонки.
     *
     * @return array<string, array{external_id: string, parent_code: string|null, level: int}>
     */
    private function snapshot(int $platformId): array
    {
        return $this->nodes->selectableNodes(platformId: $platformId)
            ->mapWithKeys(static fn (Okpd2Node $node): array => [
                (string) $node->getAttribute('code') => [
                    'external_id' => (string) $node->getAttribute('external_id'),
                    'parent_code' => $node->getAttribute('parent_code') !== null
                        ? (string) $node->getAttribute('parent_code')
                        : null,
                    'level' => (int) $node->getAttribute('level'),
                ],
            ])
            ->all();
    }

    /**
     * Коды дрейфа, которыми фильтрует хотя бы один АКТИВНЫЙ профиль этой площадки — именно они
     * дают ненулевой код возврата (Scope Included п. 6): дрейф у кода, которым никто не
     * фильтрует, — наблюдение, а не инцидент.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function activeProfileCodesAmong(int $platformId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $profileCodes = Crawler::query()
            ->where('is_active', true)
            ->whereHas('registry', static fn ($query) => $query->where('platform_id', $platformId))
            ->with('registry')
            ->get()
            ->flatMap(static function (Crawler $crawler): array {
                $filter = $crawler->filter;

                return $filter instanceof EisSearchFilter ? $filter->okpd2Codes : [];
            })
            ->all();

        return array_values(array_unique(array_intersect($codes, $profileCodes)));
    }

    /**
     * Ответ `children.html` — JSON-массив узлов с ОТДЕЛЬНЫМИ полями `code`/`key`/`name`/
     * `hasChildren` (`findings/P1-okpd2-map.md` §1): разбирать `title` не требуется. Форма,
     * отличная от снятой, — не повод «догадаться», а отказ с parentId в тексте: тихая догадка
     * здесь молча испортит карту, от которой зависит вся выборка.
     *
     * @param  array<string, mixed>  $child
     * @param  array{external_id: string, code: string|null, level: int}  $parent
     * @return array{code: string, external_id: string, parent_code: string|null, level: int, name: string}
     */
    private function nodeFrom(array $child, array $parent, string $parentId): array
    {
        $code = $child['code'] ?? null;
        $externalId = $child['key'] ?? null;
        $name = $child['name'] ?? null;

        if (! is_string($code) || $code === '' || ! is_string($name) || (! is_string($externalId) && ! is_int($externalId))) {
            throw new RuntimeException(
                "Узел children.html не несёт ожидаемых полей code/key/name (parentId={$parentId}): "
                .json_encode($child, JSON_UNESCAPED_UNICODE)
                .' — разметка площадки разошлась со снятой 2026-08-07, перепрогоните разведку.'
            );
        }

        return [
            'code' => $code,
            'external_id' => (string) $externalId,
            'parent_code' => $parent['code'],
            'level' => $parent['level'] + 1,
            'name' => $name,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchChildren(ProxyLease $lease, string $endpoint, string $parentId, StageSettings $settings): array
    {
        $response = $this->request(lease: $lease, endpoint: $endpoint, parentId: $parentId, settings: $settings);

        if ($response->failed()) {
            throw new RuntimeException(
                "children.html ответил HTTP {$response->status()} на parentId={$parentId} — "
                .'пересборка карты прервана, карта в БД не тронута.'
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException(
                "children.html вернул не JSON-массив на parentId={$parentId} — формат разошёлся "
                .'со снятым 2026-08-07 (`findings/P1-okpd2-map.md` §6.1).'
            );
        }

        return $decoded;
    }

    private function request(ProxyLease $lease, string $endpoint, string $parentId, StageSettings $settings): Response
    {
        $tlsOptions = $this->tlsTrust->optionsFor($this->canonicalHost($endpoint));

        return Http::withOptions([...$lease->httpOptions(), ...$tlsOptions])
            ->withUserAgent((string) config('crawler.user_agent'))
            ->withHeaders(['Referer' => 'https://zakupki.gov.ru/'])
            ->timeout($settings->timeoutSeconds ?? 30)
            ->retry(
                times: $settings->retries ?? 5,
                sleepMilliseconds: $settings->retryDelayMs ?? 500,
                when: static fn (Throwable $exception): bool => $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->status() >= 500),
                throw: false,
            )
            ->get($endpoint, ['parentId' => $parentId]);
    }

    private function requirePlatform(): Platform
    {
        $platform = Platform::query()->where('code', self::PLATFORM_CODE)->first();

        if (! $platform instanceof Platform) {
            throw new RuntimeException(
                'Пересборка карты ОКПД2: площадка `'.self::PLATFORM_CODE.'` не найдена — '
                .'прогоните EisPlatformSeeder.'
            );
        }

        return $platform;
    }

    private function canonicalHost(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
