<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Proxy\ProxyCandidate;
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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

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
        /** @var (Closure(): float)|null */
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

        $topK = $this->intConfig('crawler.proxy.resolver.top_k');

        while ($remaining->isNotEmpty()) {
            $pool = $this->order($remaining)->take($topK);
            $chosen = $this->weightedPick($pool);

            if ($this->needsGlobalProbe($chosen) && ! $this->health->claimGlobalProbe($chosen->proxyId)) {
                $remaining = $this->without($remaining, $chosen->proxyId);

                continue;
            }

            if ($chosen->targetId !== null && $this->needsTargetProbe($chosen) && ! $this->health->claimTargetProbe($chosen->targetId)) {
                $remaining = $this->without($remaining, $chosen->proxyId);

                continue;
            }

            return ProxyLease::forProxy(
                proxyId: $chosen->proxyId,
                host: $chosen->host,
                port: $chosen->port,
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

            $reason = $outcome->reason;

            if ($reason === null) {
                throw new LogicException('A failed proxy outcome must include its failure reason.');
            }

            if ($this->breaker->level($reason) === ProxyCircuitBreaker::LevelGlobal) {
                $this->health->updateTarget(target: $target, attributes: $targetAttributes);

                $state = $this->breaker->onFailure($proxy->fails_count, $proxy->open_count, $reason);
                $attributes = [
                    ...$this->proxyBreakerAttributes($state),
                    'last_failure_reason' => $reason->value,
                    'last_failure_at' => now(),
                ];

                if ($proxy->is_active && $state['open_count'] >= $this->intConfig('crawler.proxy.breaker.disable_after_openings')) {
                    $attributes += [
                        'is_active' => false,
                        'disabled_at' => now(),
                        'disabled_reason' => $reason->value,
                    ];
                }

                $this->health->updateProxy(proxy: $proxy, attributes: $attributes);

                return ($attributes['is_active'] ?? true) === false ? $reason->value : null;
            }

            $state = $this->breaker->onFailure($target->consecutive_failures, $target->open_count, $reason);
            $this->health->updateTarget(target: $target, attributes: [
                ...$targetAttributes,
                'consecutive_failures' => $state['consecutive_failures'],
                'open_count' => $state['open_count'],
                'open_until' => $state['open_until'],
                'last_failure_reason' => $reason->value,
            ]);

            return null;
        });

        if ($disabledReason !== null) {
            $this->operationLogs?->proxyDisabled($lease->proxyId, $disabledReason);
        }
    }

    /**
     * @return Collection<int, ProxyCandidate>
     */
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
        $country = $requirements?->country;
        $proxyIds = $requirements?->proxyIds;

        /** @var Collection<int, object{proxy_id: int|string, host: string, port: int|string, username: string|null, password: string|null, fails_count: int|string, proxy_open_count: int|string, target_id: int|string|null, success_ratio: float|int|string|null, latency_ms_avg: int|string|null, target_open_count: int|string|null}> $rows */
        $rows = DB::table('prc_proxies')
            ->leftJoin('prc_proxy_targets', function (JoinClause $join) use ($target): void {
                $join->on('prc_proxy_targets.proxy_id', '=', 'prc_proxies.id')
                    ->where('prc_proxy_targets.target', '=', $target);
            })
            ->where('prc_proxies.is_active', true)
            ->whereJsonContains('prc_proxies.scopes', $scope->value)
            ->where(fn (Builder $query): Builder => $query->whereNull('prc_proxies.open_until')->orWhere('prc_proxies.open_until', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('prc_proxy_targets.open_until')->orWhere('prc_proxy_targets.open_until', '<=', now()))
            ->whereNotIn('prc_proxies.country', $forbiddenCountries)
            ->when(
                $requiredCountry !== null,
                fn ($query) => $query->where('prc_proxies.country', $requiredCountry),
            )
            ->when(
                $country !== null,
                fn (Builder $query): Builder => $query->where('prc_proxies.country', $country),
            )
            ->when(
                $proxyIds !== null,
                fn (Builder $query): Builder => $query->whereIn('prc_proxies.id', $proxyIds),
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

        return $rows->map(static fn (object $row): ProxyCandidate => new ProxyCandidate(
            proxyId: (int) $row->proxy_id,
            host: $row->host,
            port: (int) $row->port,
            username: $row->username,
            password: $row->password,
            failsCount: (int) $row->fails_count,
            proxyOpenCount: (int) $row->proxy_open_count,
            targetId: $row->target_id === null ? null : (int) $row->target_id,
            successRatio: $row->success_ratio === null ? null : (float) $row->success_ratio,
            latencyMsAvg: $row->latency_ms_avg === null ? null : (int) $row->latency_ms_avg,
            targetOpenCount: $row->target_open_count === null ? null : (int) $row->target_open_count,
        ));
    }

    /**
     * @param  Collection<int, ProxyCandidate>  $rows
     * @return Collection<int, ProxyCandidate>
     */
    private function order(Collection $rows): Collection
    {
        return $rows
            ->map(fn (ProxyCandidate $row): ProxyCandidate => $row->withScore($this->scoreCalculator->score([
                'success_ratio' => $row->successRatio ?? 1.0,
                'latency_ms_avg' => $row->latencyMsAvg,
                'fails_count' => $row->failsCount,
            ])))
            ->sort(fn (ProxyCandidate $a, ProxyCandidate $b): int => ($b->score <=> $a->score)
                ?: ($a->failsCount <=> $b->failsCount)
                ?: ($a->proxyId <=> $b->proxyId))
            ->values();
    }

    /**
     * @param  Collection<int, ProxyCandidate>  $pool
     */
    private function weightedPick(Collection $pool): ProxyCandidate
    {
        $epsilon = $this->floatConfig('crawler.proxy.resolver.epsilon');
        /** @var Collection<int, float> $weights */
        $weights = $pool->map(fn (ProxyCandidate $row): float => max($row->score, $epsilon))->values();
        /** @var list<float> $weightValues */
        $weightValues = $weights->all();
        $total = array_sum($weightValues);

        $target = $this->nextRandomFloat() * $total;
        $cumulative = 0.0;

        foreach ($pool->values() as $index => $row) {
            $cumulative += $weights->get($index) ?? 0.0;

            if ($target <= $cumulative) {
                return $row;
            }
        }

        return $pool->last() ?? throw new LogicException('A weighted proxy pool cannot be empty.');
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
    private function needsGlobalProbe(ProxyCandidate $row): bool
    {
        return $row->proxyOpenCount > 0;
    }

    private function needsTargetProbe(ProxyCandidate $row): bool
    {
        return $row->targetId !== null && ($row->targetOpenCount ?? 0) > 0;
    }

    /**
     * @param  Collection<int, ProxyCandidate>  $rows
     * @return Collection<int, ProxyCandidate>
     */
    private function without(Collection $rows, int $proxyId): Collection
    {
        return $rows->reject(fn (ProxyCandidate $row): bool => $row->proxyId === $proxyId)->values();
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
        $alpha = $this->floatConfig('crawler.proxy.resolver.ewma_alpha');
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

    private function intConfig(string $key): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException("Expected an integer config value for {$key}.");
    }

    private function floatConfig(string $key): float
    {
        $value = config($key);

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new LogicException("Expected a numeric config value for {$key}.");
    }
}
