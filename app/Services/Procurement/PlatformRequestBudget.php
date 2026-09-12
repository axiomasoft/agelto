<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\PlatformThrottleSettings;
use App\Dto\Procurement\Crawler\StageSettings;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Единый fixed-window бюджет исходящих запросов на хост площадки (D42–D45).
 *
 * Queue middleware не подходит: release расходует единственную попытку job (`tries = 1`).
 * `Redis::throttle()` не используется, чтобы `array`-store оставался полноценным тестовым
 * контуром. Fixed-window допускает всплеск на границе минуты; стадийный джиттер внутри
 * этого сервиса размыкает синхронные запросы, но не обещает FIFO-справедливость.
 */
final readonly class PlatformRequestBudget
{
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private CrawlSettingsResolver $crawlSettings,
        private ProxyBindingResolver $proxyBindings,
    ) {}

    public function acquire(string $host, CrawlStage $stage, ?Crawler $crawler = null, ?float $deadlineAt = null): int
    {
        $host = $this->canonicalHost($host);
        $platform = $this->platformFor(host: $host, crawler: $crawler);
        $capacity = $this->capacity(platform: $platform);
        $maxWaitSeconds = $this->maxWaitSeconds(platform: $platform);
        $key = "crawl:budget:{$host}";
        $slotWaitedMs = 0;

        if ($capacity <= 0) {
            throw PlatformBudgetExhaustedException::forHost(host: $host, maxWaitSeconds: $maxWaitSeconds);
        }

        while (! $this->claimSlot(key: $key, capacity: $capacity)) {
            $remainingMs = ($maxWaitSeconds * 1000) - $slotWaitedMs;

            if ($remainingMs <= 0) {
                throw PlatformBudgetExhaustedException::forHost(host: $host, maxWaitSeconds: $maxWaitSeconds);
            }

            $waitMs = min(
                $remainingMs,
                max(1000, RateLimiter::availableIn(key: $key) * 1000),
            );

            $this->assertWaitFitsDeadline(waitMs: $waitMs, deadlineAt: $deadlineAt, stage: $stage);

            Sleep::for($waitMs)->milliseconds();
            $slotWaitedMs += $waitMs;
        }

        $stagePauseMs = $this->stagePauseMs(stage: $stage, platform: $platform, crawler: $crawler);

        if ($stagePauseMs > 0) {
            $this->assertWaitFitsDeadline(waitMs: $stagePauseMs, deadlineAt: $deadlineAt, stage: $stage);
            Sleep::for($stagePauseMs)->milliseconds();
        }

        return $slotWaitedMs + $stagePauseMs;
    }

    private function assertWaitFitsDeadline(int $waitMs, ?float $deadlineAt, CrawlStage $stage): void
    {
        if ($deadlineAt === null) {
            return;
        }

        if (microtime(true) + ($waitMs / 1000) >= $deadlineAt) {
            throw OutboundRequestDeadlineExceeded::forStage(stage: $stage->value);
        }
    }

    private function claimSlot(string $key, int $capacity): bool
    {
        return RateLimiter::attempt(
            key: $key,
            maxAttempts: $capacity,
            callback: static fn (): bool => true,
            decaySeconds: self::WINDOW_SECONDS,
        ) === true;
    }

    private function capacity(?Platform $platform): int
    {
        $platformThrottle = $this->platformThrottle(platform: $platform);
        $base = $platformThrottle === null
            ? $this->intConfig('crawler.budget.requests_per_minute_base')
            : ($platformThrottle->requestsPerMinuteBase ?? $this->intConfig('crawler.budget.requests_per_minute_base'));
        $perProxy = $platformThrottle === null
            ? $this->intConfig('crawler.budget.requests_per_minute_per_proxy')
            : ($platformThrottle->requestsPerMinutePerProxy ?? $this->intConfig('crawler.budget.requests_per_minute_per_proxy'));

        return $base + ($perProxy * $this->activeBoundProxyCount(platform: $platform));
    }

    private function maxWaitSeconds(?Platform $platform): int
    {
        $platformThrottle = $this->platformThrottle(platform: $platform);

        return max(
            0,
            $platformThrottle === null
                ? $this->intConfig('crawler.budget.max_wait_seconds')
                : ($platformThrottle->maxWaitSeconds ?? $this->intConfig('crawler.budget.max_wait_seconds')),
        );
    }

    private function platformThrottle(?Platform $platform): ?PlatformThrottleSettings
    {
        $throttle = $platform?->getAttribute('throttle');

        return $throttle instanceof PlatformThrottleSettings ? $throttle : null;
    }

    private function stagePauseMs(CrawlStage $stage, ?Platform $platform, ?Crawler $crawler): int
    {
        $settings = $platform === null
            ? $this->fallbackStageSettings(stage: $stage)
            : $this->crawlSettings->forStage(stage: $stage, platform: $platform, crawler: $crawler);
        $minMs = max(0, $settings->minPauseMs ?? 0);
        $maxMs = max($minMs, $settings->maxPauseMs ?? $minMs);

        return random_int($minMs, $maxMs);
    }

    private function fallbackStageSettings(CrawlStage $stage): StageSettings
    {
        $settings = $this->jsonObject(config("crawler.stages.{$stage->value}", []), "crawler.stages.{$stage->value}");

        return new StageSettings(
            minPauseMs: $this->nullableInt($settings['min_pause_ms'] ?? null, 'min_pause_ms'),
            maxPauseMs: $this->nullableInt($settings['max_pause_ms'] ?? null, 'max_pause_ms'),
            retries: $this->nullableInt($settings['retries'] ?? null, 'retries'),
            retryDelayMs: $this->nullableInt($settings['retry_delay_ms'] ?? null, 'retry_delay_ms'),
            timeoutSeconds: $this->nullableInt($settings['timeout_seconds'] ?? null, 'timeout_seconds'),
        );
    }

    private function activeBoundProxyCount(?Platform $platform): int
    {
        if ($platform === null) {
            return 0;
        }

        $proxyIds = Crawler::query()
            ->where('is_active', true)
            ->where('proxy_mode', CrawlerProxyMode::Profile)
            ->whereHas('registry', static fn ($query) => $query->where('platform_id', $platform->id))
            ->get()
            ->flatMap(fn (Crawler $crawler): array => $this->proxyBindings->forCrawler(crawler: $crawler))
            ->unique()
            ->values()
            ->all();

        if ($proxyIds === []) {
            return 0;
        }

        return Proxy::query()
            ->whereKey($proxyIds)
            ->where('is_active', true)
            ->count();
    }

    private function platformFor(string $host, ?Crawler $crawler): ?Platform
    {
        if ($crawler !== null) {
            $platformId = $crawler->registry()->value('platform_id');

            return $platformId === null
                ? null
                : Platform::query()->find($this->nullableInt($platformId, 'crawler registry platform_id')
                    ?? throw new \LogicException('crawler registry platform_id не должен быть null.'));
        }

        $platforms = Platform::query()
            ->where('is_active', true)
            ->whereNotNull('base_url')
            ->get();

        foreach ($platforms as $platform) {
            $baseUrl = $platform->getAttribute('base_url');

            if (is_string($baseUrl) && $this->canonicalHost($baseUrl) === $host) {
                return $platform;
            }
        }

        return null;
    }

    private function canonicalHost(string $host): string
    {
        $parsedHost = parse_url(str_contains($host, '://') ? $host : "https://{$host}", PHP_URL_HOST);

        return strtolower(trim(is_string($parsedHost) ? $parsedHost : '', '.'));
    }

    private function intConfig(string $key): int
    {
        return $this->nullableInt(config($key), $key)
            ?? throw new \LogicException("{$key} должен быть целым числом.");
    }

    private function nullableInt(mixed $value, string $context): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        throw new \LogicException("{$context} должен быть целым числом или null.");
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \LogicException("{$context} должен быть объектом конфигурации.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
