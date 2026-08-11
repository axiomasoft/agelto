<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Enums\Procurement\Proxy\ProxyRouteSource;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Exceptions\Procurement\Proxy\ProxyPolicyMisconfiguredException;
use App\Models\Procurement\Crawler\ProxyTarget;
use App\Repositories\Procurement\Proxy\ProxyHealthStoreRepository;
use App\Services\Procurement\OperationLogWriter;
use Closure;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Единственная точка выдачи прокси для всех точек исхода (research/02 §9.1): фильтр по
 * скоупу/требованию/breaker'у → score → weighted random внутри top-K (D5, D6). Гейт
 * запрещённых стран для скоупа `llm` сюда НЕ входит — жёсткая политика конфига добавляется
 * `PoolOutboundProxy`/резолвером в P1.5 поверх этого фильтра, не взамен него.
 */
final readonly class ProxyResolver
{
    public function __construct(
        private ProxyScoreCalculator $scoreCalculator,
        private ProxyCircuitBreaker $breaker,
        private ProxyHealthStoreRepository $health,
        private CrawlerProxySettingsResolver $crawlerSettings,
        private ?Closure $randomSource = null,
        private ?OperationLogWriter $operationLogs = null,
    ) {}

    /**
     * @throws NoProxyAvailableException пул кандидатов пуст либо весь пул занят пробой
     * @throws ProxyPolicyMisconfiguredException пустая политика запретов для скоупа `llm` (D84)
     */
    public function resolve(ProxyScope $scope, string $target, ?ProxyRequirements $requirements = null): ProxyLease
    {
        if ($requirements?->crawlerMode === CrawlerProxyMode::Direct) {
            return $this->directLease(scope: $scope, target: $target);
        }

        $source = null;

        if ($requirements?->crawlerMode === CrawlerProxyMode::Default) {
            $defaultProxyId = $this->crawlerSettings->defaultProxyId();

            if ($defaultProxyId === null) {
                return $this->directLease(scope: $scope, target: $target);
            }

            $requirements = new ProxyRequirements(proxyIds: [$defaultProxyId]);
            $source = ProxyRouteSource::Default;
        }

        if ($requirements?->crawlerMode === CrawlerProxyMode::Profile) {
            if ($requirements->proxyIds === []) {
                $this->throwNoProxyAvailable(scope: $scope, target: $target, reason: 'profile mode requires nonempty proxy bindings');
            }

            $requirements = new ProxyRequirements(country: $requirements->country, proxyIds: $requirements->proxyIds);
            $source = ProxyRouteSource::Profile;
        }

        $remaining = $this->candidates($scope, $target, $requirements);

        if ($remaining->isEmpty()) {
            $this->throwNoProxyAvailable($scope, $target, 'фильтр по скоупу/требованию/breaker\'у вернул пустое множество');
        }

        $topK = (int) config('crawler.proxy.resolver.top_k');

        while ($remaining->isNotEmpty()) {
            $pool = $this->order($remaining)->take($topK);
            $chosen = $this->weightedPick($pool);

            if ($this->needsGlobalProbe($chosen) && ! $this->health->claimGlobalProbe((int) $chosen->proxy_id)) {
                $remaining = $this->without($remaining, (int) $chosen->proxy_id);

                continue;
            }

            if ($this->needsTargetProbe($chosen) && ! $this->health->claimTargetProbe((int) $chosen->target_id)) {
                $remaining = $this->without($remaining, (int) $chosen->proxy_id);

                continue;
            }

            return ProxyLease::forProxy(
                proxyId: (int) $chosen->proxy_id,
                host: $chosen->host,
                port: (int) $chosen->port,
                username: $chosen->username,
                password: $chosen->password,
                scope: $scope,
                target: $target,
                source: $source,
            );
        }

        $this->throwNoProxyAvailable($scope, $target, 'весь отфильтрованный пул занят half-open пробой');
    }

    /**
     * Тихого прямого запроса не существует (research/02 §10) — отказ ВСЕГДА виден в логе,
     * не только исключением, которое где-то по цепочке может быть проглочено `catch`'ем.
     */
    private function throwNoProxyAvailable(ProxyScope $scope, string $target, string $reason): never
    {
        Log::warning('Резолвер прокси: нет доступного узла', [
            'scope' => $scope->value,
            'target' => $target,
            'reason' => $reason,
        ]);

        throw new NoProxyAvailableException($scope, $target, $reason);
    }

    private function directLease(ProxyScope $scope, string $target): ProxyLease
    {
        Log::info('Резолвер прокси: прямой маршрут', [
            'scope' => $scope->value,
            'target' => $target,
            'proxy_source' => ProxyRouteSource::Direct->value,
        ]);

        return ProxyLease::direct(scope: $scope, target: $target);
    }

    public function reportOutcome(ProxyLease $lease, ProxyOutcome $outcome): void
    {
        if ($lease->isDirect || $lease->proxyId === null) {
            return;
        }

        $disabledReason = DB::transaction(function () use ($lease, $outcome): ?string {
            ['proxy' => $proxy, 'target' => $target] = $this->health->lockProxyAndTarget(
                proxyId: $lease->proxyId,
                target: $lease->target,
            );
            $targetAttributes = [...$this->ewmaAttributes($target, $outcome), 'last_outcome_at' => now()];

            if ($outcome->successful) {
                $this->health->updateTarget($target, [...$targetAttributes, ...$this->breaker->onSuccess()]);
                $this->health->updateProxy(
                    proxy: $proxy,
                    attributes: [...$this->proxyBreakerAttributes($this->breaker->onSuccess()), 'last_success_at' => now()],
                );

                return null;
            }

            if ($this->breaker->level($outcome->reason) === ProxyCircuitBreaker::LevelGlobal) {
                $this->health->updateTarget(target: $target, attributes: $targetAttributes);

                $state = $this->breaker->onFailure($proxy->fails_count, $proxy->open_count, $outcome->reason);
                $attributes = [
                    ...$this->proxyBreakerAttributes($state),
                    'last_failure_reason' => $outcome->reason->value,
                    'last_failure_at' => now(),
                ];

                if ($proxy->is_active && $state['open_count'] >= (int) config('crawler.proxy.breaker.disable_after_openings')) {
                    $attributes += [
                        'is_active' => false,
                        'disabled_at' => now(),
                        'disabled_reason' => $outcome->reason->value,
                    ];
                }

                $this->health->updateProxy(proxy: $proxy, attributes: $attributes);

                return ($attributes['is_active'] ?? true) === false ? $outcome->reason->value : null;
            }

            $state = $this->breaker->onFailure($target->consecutive_failures, $target->open_count, $outcome->reason);
            $this->health->updateTarget(target: $target, attributes: [
                ...$targetAttributes,
                'consecutive_failures' => $state['consecutive_failures'],
                'open_count' => $state['open_count'],
                'open_until' => $state['open_until'],
                'last_failure_reason' => $outcome->reason->value,
            ]);

            return null;
        });

        if ($disabledReason !== null) {
            $this->operationLogs?->proxyDisabled($lease->proxyId, $disabledReason);
        }
    }

    private function candidates(ProxyScope $scope, string $target, ?ProxyRequirements $requirements): Collection
    {
        // Гейт запрещённых стран (research/02 §8, D5) — ДО скоринга, жёсткий SQL-фильтр, не вес.
        // `whereNotIn` с пустым списком компилируется в `1 = 1` (Grammar::whereNotIn) — скоуп без
        // запретов не сужается; с непустым списком SQL `NOT IN` возвращает NULL (= false в WHERE)
        // для строк с `country IS NULL`, поэтому неизвестная страна автоматически запрещена —
        // ровно то поведение, которого требует §8, без отдельного предиката на NULL.
        $forbiddenCountries = (array) config("crawler.proxy.policy.{$scope->value}.forbidden_countries", []);

        // Предохранитель F11 (D84): пустая политика запретов для `llm` — неверная конфигурация,
        // не «запретов нет» (`whereNotIn([])` компилируется в `1 = 1`, гейт молча не сужает пул).
        // СВОЙ тип исключения — `PoolOutboundProxy` ловит `NoProxyAvailableException` и фолбэчит
        // на env без гейта стран; тот же тип здесь превратил бы предохранитель в тихий обход.
        if ($scope === ProxyScope::Llm && $forbiddenCountries === []) {
            throw new ProxyPolicyMisconfiguredException($scope);
        }

        // Гео-политика скоупа (research/02 §8, P1.9, D83) — пол площадки, СКЛАДЫВАЕТСЯ с
        // требованием профиля (`$requirements->country`), не заменяет его: конфликт двух стран
        // даёт пустое множество и штатный `NoProxyAvailableException`.
        $requiredCountry = config("crawler.proxy.policy.{$scope->value}.required_country");

        return DB::table('prc_proxies')
            ->leftJoin('prc_proxy_targets', function (JoinClause $join) use ($target): void {
                $join->on('prc_proxy_targets.proxy_id', '=', 'prc_proxies.id')
                    ->where('prc_proxy_targets.target', '=', $target);
            })
            ->where('prc_proxies.is_active', true)
            ->whereJsonContains('prc_proxies.scopes', $scope->value)
            ->where(fn ($query) => $query->whereNull('prc_proxies.open_until')->orWhere('prc_proxies.open_until', '<=', now()))
            ->where(fn ($query) => $query->whereNull('prc_proxy_targets.open_until')->orWhere('prc_proxy_targets.open_until', '<=', now()))
            ->whereNotIn('prc_proxies.country', $forbiddenCountries)
            ->when(
                $requiredCountry !== null,
                fn ($query) => $query->where('prc_proxies.country', $requiredCountry),
            )
            ->when(
                $requirements?->country !== null,
                fn ($query) => $query->where('prc_proxies.country', $requirements->country),
            )
            ->when(
                $requirements?->proxyIds !== null,
                fn ($query) => $query->whereIn('prc_proxies.id', $requirements->proxyIds),
            )
            ->select([
                'prc_proxies.id as proxy_id',
                'prc_proxies.host',
                'prc_proxies.port',
                'prc_proxies.username',
                'prc_proxies.password',
                'prc_proxies.fails_count',
                'prc_proxies.open_count as proxy_open_count',
                'prc_proxy_targets.id as target_id',
                'prc_proxy_targets.success_ratio',
                'prc_proxy_targets.latency_ms_avg',
                'prc_proxy_targets.open_count as target_open_count',
            ])
            ->get();
    }

    private function order(Collection $rows): Collection
    {
        return $rows
            ->map(fn ($row) => (object) [
                ...(array) $row,
                'score' => $this->scoreCalculator->score([
                    'success_ratio' => $row->success_ratio !== null ? (float) $row->success_ratio : 1.0,
                    'latency_ms_avg' => $row->latency_ms_avg !== null ? (int) $row->latency_ms_avg : null,
                    'fails_count' => (int) $row->fails_count,
                ]),
            ])
            ->sort(fn ($a, $b) => ($b->score <=> $a->score)
                ?: ((int) $a->fails_count <=> (int) $b->fails_count)
                ?: ((int) $a->proxy_id <=> (int) $b->proxy_id))
            ->values();
    }

    private function weightedPick(Collection $pool): object
    {
        $epsilon = (float) config('crawler.proxy.resolver.epsilon');
        $weights = $pool->map(fn ($row) => max($row->score, $epsilon))->values();
        $total = $weights->sum();

        $target = $this->nextRandomFloat() * $total;
        $cumulative = 0.0;

        foreach ($pool->values() as $index => $row) {
            $cumulative += $weights->get($index);

            if ($target <= $cumulative) {
                return $row;
            }
        }

        return $pool->last();
    }

    private function nextRandomFloat(): float
    {
        return $this->randomSource !== null
            ? ($this->randomSource)()
            : random_int(0, 1_000_000) / 1_000_000;
    }

    /**
     * Половина открытых кандидатов уже прошла фильтр «`open_until` не в будущем» —
     * `open_count > 0` здесь однозначно значит half-open, не open (иначе строка не попала бы
     * в выборку).
     */
    private function needsGlobalProbe(object $row): bool
    {
        return (int) $row->proxy_open_count > 0;
    }

    private function needsTargetProbe(object $row): bool
    {
        return $row->target_id !== null && (int) $row->target_open_count > 0;
    }

    private function without(Collection $rows, int $proxyId): Collection
    {
        return $rows->reject(fn ($row) => (int) $row->proxy_id === $proxyId)->values();
    }

    /**
     * `prc_proxies` несёт серию глобальных отказов в существующей колонке `fails_count`, не
     * `consecutive_failures` (research/02 §3.1 — колонка не переименовывается).
     *
     * @param  array{consecutive_failures: int, open_count: int, open_until: mixed}  $state
     * @return array{fails_count: int, open_count: int, open_until: mixed}
     */
    private function proxyBreakerAttributes(array $state): array
    {
        return [
            'fails_count' => $state['consecutive_failures'],
            'open_count' => $state['open_count'],
            'open_until' => $state['open_until'],
        ];
    }

    /**
     * @return array{success_ratio: float, latency_ms_avg: ?int}
     */
    private function ewmaAttributes(ProxyTarget $target, ProxyOutcome $outcome): array
    {
        $alpha = (float) config('crawler.proxy.resolver.ewma_alpha');
        $successValue = $outcome->successful ? 1.0 : 0.0;
        $successRatio = $target->success_ratio * (1 - $alpha) + $successValue * $alpha;

        $latency = $target->latency_ms_avg;

        if ($outcome->successful && $outcome->latencyMs !== null) {
            $latency = $latency === null
                ? $outcome->latencyMs
                : (int) round($latency * (1 - $alpha) + $outcome->latencyMs * $alpha);
        }

        return ['success_ratio' => $successRatio, 'latency_ms_avg' => $latency];
    }
}
