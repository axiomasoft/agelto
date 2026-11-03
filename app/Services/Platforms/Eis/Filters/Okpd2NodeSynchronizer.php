<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Filters;

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\SafeUrl;
use App\Enums\Procurement\Network\TrafficClass;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use App\Repositories\Procurement\Platform\Okpd2NodeStoreRepository;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Http\Client\Response;
use RuntimeException;

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
 * `ProxyResolver` → canonical owner/permit/executor → TLS trust из `SourceTlsTrust`.
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
        private LegacyOutbound $outbound,
        private EisPolicy $policy,
    ) {}

    /**
     * @param  string|null  $root  id узла-корня НСИ; `null` — `config('platforms.eis.okpd2.root_external_id')`
     * @param  int|null  $maxRequests  потолок запросов к площадке; `null` — `config('platforms.eis.okpd2.max_requests')`
     * @return array{
     *     requests: int,
     *     nodes_seen: int,
     *     new: list<array{code: string, external_id: string}>,
     *     missing: list<array{code: string, external_id: string}>,
     *     changed: list<array{code: string, external_id: string, stored_external_id: string}>,
     *     not_traversed: list<string>,
     *     drifted_codes: list<string>,
     *     written: bool,
     * }
     */
    public function run(?string $root = null, bool $dryRun = false, ?int $maxRequests = null): array
    {
        $platform = $this->requirePlatform();
        $rootId = $root ?? $this->policy->referenceRoot;
        $limit = $maxRequests ?? $this->policy->referenceMaxRequests;

        $stored = $this->snapshot(platformId: (int) $platform->id);

        // Нумерацию детей задаёт уровень корня. Сам корень в карту не пишется, поэтому обычно
        // его там нет — тогда уровень берётся из конфига (раздел = 1).
        $rootLevel = $this->policy->referenceRootLevel;

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
            'drifted_codes' => $driftedCodes,
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
        $endpoint = $this->policy->referenceEndpoint;
        $lease = $this->proxyResolver->profile();

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

            $children = $this->fetchChildren(lease: $lease, endpoint: $endpoint, parentId: $current['external_id']);
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
            ->mapWithKeys(fn (Okpd2Node $node): array => [
                $this->attributeString($node, 'code') => [
                    'external_id' => $this->attributeString($node, 'external_id'),
                    'parent_code' => $node->getAttribute('parent_code') !== null
                        ? $this->attributeString($node, 'parent_code')
                        : null,
                    'level' => $this->attributeInt($node, 'level'),
                ],
            ])
            ->all();
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
    private function fetchChildren(RoutePolicy $lease, string $endpoint, string $parentId): array
    {
        $response = $this->request(lease: $lease, endpoint: $endpoint, parentId: $parentId);

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

        $children = [];

        foreach ($decoded as $child) {
            if (! is_array($child)) {
                throw new RuntimeException("children.html вернул не объект узла на parentId={$parentId}.");
            }

            foreach (array_keys($child) as $key) {
                if (! is_string($key)) {
                    throw new RuntimeException("children.html вернул нестроковый ключ узла на parentId={$parentId}.");
                }
            }

            /** @var array<string, mixed> $child */
            $children[] = $child;
        }

        return $children;
    }

    private function request(RoutePolicy $lease, string $endpoint, string $parentId): Response
    {
        $platform = $this->requirePlatform();
        $url = $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query(['parentId' => $parentId]);

        $platformCode = $platform->getAttribute('code');
        if (! is_string($platformCode)) {
            throw new \LogicException('Platform code is unavailable.');
        }

        return $this->outbound->request(callerCode: 'legacy.reference_sync', platformId: $platform->id, providerKey: null,
            capability: 'reference_sync', url: new SafeUrl($url),
            trafficClass: TrafficClass::ReferenceSync, routePolicy: $lease,
            controls: ['system', 'acquisition', 'acquisition:platform:'.$platformCode], deadlineSeconds: 90,
            payload: new HttpRequestPayload([
                'user-agent' => config()->string('outbound.http.user_agent'), 'referer' => 'https://zakupki.gov.ru/',
            ]));
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

    private function attributeString(Okpd2Node $node, string $attribute): string
    {
        $value = $node->getAttribute($attribute);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Узел ОКПД2 {$attribute} должен быть непустой строкой.");
        }

        return $value;
    }

    private function attributeInt(Okpd2Node $node, string $attribute): int
    {
        $value = $node->getAttribute($attribute);

        if (! is_int($value)) {
            throw new RuntimeException("Узел ОКПД2 {$attribute} должен быть целым числом.");
        }

        return $value;
    }
}
