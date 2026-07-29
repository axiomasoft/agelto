<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Contracts\SupportsContextCaching;
use App\Services\Llm\Dto\CacheableContent;
use App\Services\Llm\Dto\LlmCacheHandle;
use App\Services\Llm\Dto\LlmCacheOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CRUD explicit context cache поверх `SupportsContextCaching` (ТЗ 04 §2.1, §8 — «дописываем сами
 * поверх Prism»): создание/refresh/удаление регистрируются на `Analysis` (`cache_id`/
 * `cache_expires_at`/`rules_hash`, миграция `2026_07_04_100025` — та же тройка, что v1
 * `gemini_cache_id`/`gemini_cache_expires_at`/`gemini_rules_hash`, research/B §5). Реестр «живых»
 * кэшей — это САМИ `Analysis`-строки с `cache_id IS NOT NULL`, отдельной таблицы не заводим
 * (одна explicit-сессия кэша = один анализ, `document_version_id` на `Analysis` неизменен —
 * v1-концепт «file fingerprint» в инвалидации сводится к неизменности этой связи, отдельного
 * поля не требует, Known Deviations P7.1).
 *
 * Манагер провайдер-агностичен (Implementation Rules): принимает `LlmProvider` параметром,
 * `instanceof SupportsContextCaching` проверяется здесь — вызывающий код (оркестратор/команда)
 * не обязан знать, поддерживает ли активный маршрут explicit-кэш. Ошибка похода к провайдеру —
 * warning в лог, НЕ exception (путь без кэша всегда доступен через implicit-кэширование, D8).
 */
final readonly class LlmCacheManager
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
    ) {}

    /**
     * Создаёт explicit cache и регистрирует его на `Analysis`. Провайдер не поддерживает
     * `SupportsContextCaching` (например, будущий OpenAI-совместимый локальный driver, D14) —
     * `null` без похода в API (implicit-only путь для такого провайдера, не ошибка).
     */
    public function create(
        Analysis $analysis,
        LlmProvider $provider,
        CacheableContent $content,
        string $model,
        CarbonInterval $ttl,
        string $rulesHash,
    ): ?LlmCacheHandle {
        if (! $provider instanceof SupportsContextCaching) {
            return null;
        }

        try {
            $handle = $provider->createCache($content, new LlmCacheOptions(model: $model, ttl: $ttl));
        } catch (Throwable $exception) {
            Log::warning('LlmCacheManager: не удалось создать explicit context cache — путь без кэша.', [
                'analysis_id' => $analysis->id,
                'model' => $model,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $this->analyses->update($analysis, [
            'cache_id' => $handle->id,
            'cache_expires_at' => $handle->expiresAt,
            'rules_hash' => $rulesHash,
        ]);

        return $handle;
    }

    /**
     * Handle БЕЗ похода в API, если зарегистрированный на `Analysis` кэш ещё валиден — v1-канон
     * `isCacheValid` (research/B §5): `rules_hash` совпадает (промты не менялись с момента
     * создания) И TTL не истёк. Промты сменились/TTL истёк/кэша не было — `null`, вызывающий код
     * создаёт новый через `create()`.
     */
    public function validHandle(Analysis $analysis, string $rulesHash): ?LlmCacheHandle
    {
        if ($analysis->cache_id === null || $analysis->cache_expires_at === null) {
            return null;
        }

        if ($analysis->rules_hash !== $rulesHash) {
            return null;
        }

        if ($analysis->cache_expires_at->isPast()) {
            return null;
        }

        return new LlmCacheHandle(id: $analysis->cache_id, expiresAt: CarbonImmutable::instance($analysis->cache_expires_at));
    }

    /**
     * Продлевает TTL зарегистрированного кэша (длинный разбор, много self-requeue-прогонов).
     * Нет активного кэша на анализе ИЛИ провайдер не поддерживает caching — no-op.
     */
    public function refresh(Analysis $analysis, LlmProvider $provider, CarbonInterval $ttl): void
    {
        if ($analysis->cache_id === null || ! $provider instanceof SupportsContextCaching) {
            return;
        }

        $handle = new LlmCacheHandle(id: $analysis->cache_id, expiresAt: CarbonImmutable::now());

        try {
            $provider->refreshCache($handle, $ttl);
        } catch (Throwable $exception) {
            Log::warning('LlmCacheManager: не удалось продлить TTL explicit context cache.', [
                'analysis_id' => $analysis->id,
                'cache_id' => $analysis->cache_id,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $this->analyses->update($analysis, [
            'cache_expires_at' => CarbonImmutable::now()->add($ttl),
        ]);
    }

    /**
     * Удаляет explicit cache у провайдера (best-effort — ошибка логируется, не бросается: кэш
     * протухнет сам по TTL) и ВСЕГДА снимает регистрацию с `Analysis` (даже если провайдер не
     * поддерживает `SupportsContextCaching` — освобождает «осиротевшую» запись после смены
     * маршрута/провайдера на driver без caching).
     */
    public function delete(Analysis $analysis, LlmProvider $provider): void
    {
        if ($analysis->cache_id === null) {
            return;
        }

        if ($provider instanceof SupportsContextCaching) {
            try {
                $provider->deleteCache(new LlmCacheHandle(id: $analysis->cache_id, expiresAt: CarbonImmutable::now()));
            } catch (Throwable $exception) {
                Log::warning('LlmCacheManager: ошибка удаления explicit context cache у провайдера.', [
                    'analysis_id' => $analysis->id,
                    'cache_id' => $analysis->cache_id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $this->analyses->update($analysis, [
            'cache_id' => null,
            'cache_expires_at' => null,
            'rules_hash' => null,
        ]);
    }

    /**
     * Живые explicit-кэши (`ai:caches list`) — анализы с зарегистрированным `cache_id`.
     *
     * @return Collection<int, Analysis>
     */
    public function listActive(): Collection
    {
        return Analysis::query()->whereNotNull('cache_id')->orderBy('cache_expires_at')->get();
    }

    /**
     * Осиротевшие/просроченные кэши (`ai:caches prune`, Code Guidance): TTL истёк ИЛИ анализ уже
     * финализирован (терминальный статус — succeeded/failed/skipped, `AnalysisStatus`), но
     * `cache_id` не был снят (job упал между вызовом провайдера и обновлением статуса анализа).
     * `$providerResolver` резолвит `LlmProvider` по строке `analysis.provider` — манагер сам
     * драйверы не резолвит (Implementation Rules: «манагер не знает про Gemini»).
     *
     * @param  callable(string): LlmProvider  $providerResolver
     * @return int количество удалённых регистраций
     */
    public function prune(callable $providerResolver): int
    {
        $stale = Analysis::query()
            ->whereNotNull('cache_id')
            ->where(function ($query): void {
                $query->where('cache_expires_at', '<=', CarbonImmutable::now())
                    ->orWhereIn('status', ['succeeded', 'failed', 'skipped']);
            })
            ->get();

        $pruned = 0;

        foreach ($stale as $analysis) {
            if ($analysis->provider === null) {
                // Регистрация без известного провайдера (не должна возникать в норме) —
                // снимаем с Analysis без похода в API, иначе осиротевшая запись живёт вечно.
                $this->analyses->update($analysis, ['cache_id' => null, 'cache_expires_at' => null, 'rules_hash' => null]);
                $pruned++;

                continue;
            }

            $this->delete($analysis, $providerResolver($analysis->provider));
            $pruned++;
        }

        return $pruned;
    }

    /**
     * Доля implicit cache-хитов (`cached_tokens / (prompt_tokens + cached_tokens)`) по `purpose`
     * за окно (`ai:caches list --hits`, Code Guidance: «выводить в консоль таблицей по purpose»).
     * `prompt_tokens` НЕ включает `cached_tokens` (докблок `LlmUsage`/`PrismGeminiProvider`) —
     * знаменатель восстанавливает полный размер входного промта.
     *
     * @return Collection<int, object{purpose: string, calls: int, prompt_tokens: int, cached_tokens: int, hit_ratio: float}>
     */
    public function implicitHitStats(CarbonInterval $window): Collection
    {
        $since = CarbonImmutable::now()->sub($window);

        return LlmCall::query()
            ->selectRaw('purpose')
            ->selectRaw('count(*) as calls')
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('coalesce(sum(cached_tokens), 0) as cached_tokens')
            ->where('called_at', '>=', $since)
            ->groupBy('purpose')
            ->orderBy('purpose')
            ->get()
            ->map(static function (LlmCall $row): object {
                $total = (int) $row->prompt_tokens + (int) $row->cached_tokens;

                return (object) [
                    'purpose' => $row->purpose->value,
                    'calls' => (int) $row->calls,
                    'prompt_tokens' => (int) $row->prompt_tokens,
                    'cached_tokens' => (int) $row->cached_tokens,
                    'hit_ratio' => $total > 0 ? round((int) $row->cached_tokens / $total, 4) : 0.0,
                ];
            });
    }
}
