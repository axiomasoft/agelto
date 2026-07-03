<?php

declare(strict_types=1);

namespace App\Process\Crawling\Run;

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\CrawlerRun;
use App\Services\AppLogger;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Управляет жизненным циклом прогона краулера (plt_crawler_runs): старт/возобновление,
 * heartbeat, чекпойнт курсора, завершение и фиксация падений. Соблюдает инвариант
 * «не более одного активного (running) прогона на профиль» (частичный уникальный индекс)
 * и пишет вехи в журнал sys_logs — чтобы было видно «упал тут — продолжил оттуда».
 */
final class CrawlerRunManager
{
    /** Если по running-прогону нет heartbeat дольше этого порога — считаем его оборванным (краш). */
    public const HEARTBEAT_STALE_MINUTES = 15;

    /** Колонки лёгкого курсора, которые двигаются по ходу обхода. */
    private const CURSOR_KEYS = [
        'cursor_url',
        'current_page',
        'price_from',
        'price_to',
        'price_stack',
        'day_from',
        'day_to',
        'cursor_offset',
    ];

    public function __construct(private readonly AppLogger $logger) {}

    /**
     * Создаёт новый прогон, либо возобновляет оборванный. Возвращает null, если по профилю
     * уже идёт живой прогон (свежий heartbeat) — тогда новый запуск молча не стартует.
     */
    public function startOrResume(Crawler $crawler): ?CrawlerRun
    {
        $active = CrawlerRun::query()
            ->where('crawler_id', $crawler->id)
            ->where('status', CrawlerRunStatus::Running)
            ->first();

        if ($active !== null) {
            if (! $this->isStale($active)) {
                $this->logger->warning(
                    "Краулер #{$crawler->id}: активный прогон #{$active->id} ещё идёт (heartbeat свежий) — новый запуск пропущен.",
                    context: $this->context($active),
                );

                return null;
            }

            return $this->recoverOrphan($active);
        }

        try {
            $run = CrawlerRun::query()->create([
                'crawler_id' => $crawler->id,
                'status' => CrawlerRunStatus::Running,
                'attempts' => 0,
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Гонка за инвариант единственного активного прогона — кто-то успел раньше.
            $this->logger->warning(
                "Краулер #{$crawler->id}: не удалось создать прогон (вероятно, активный уже стартовал): {$e->getMessage()}",
                context: ['crawler_id' => $crawler->id],
            );

            return null;
        }

        $this->logger->event("Старт прогона краулера #{$run->id}.", $this->context($run));

        return $run;
    }

    /**
     * Обновляет heartbeat и (опционально) лёгкий курсор обхода. Зовётся на каждой итерации пагинации.
     *
     * @param  array<string, mixed>  $cursor
     */
    public function heartbeat(CrawlerRun $run, array $cursor = []): void
    {
        $update = ['last_heartbeat_at' => now()];

        foreach (self::CURSOR_KEYS as $key) {
            if (array_key_exists($key, $cursor)) {
                $update[$key] = $cursor[$key];
            }
        }

        $run->update($update);
    }

    /** Сбрасывает курсор — для оперативного режима (полный проход с нуля). */
    public function resetCursor(CrawlerRun $run): void
    {
        $run->update($this->emptyCursor());
    }

    /** Штатное завершение: курсор обнуляем, чтобы следующий регулярный прогон стартовал с начала. */
    public function complete(CrawlerRun $run): void
    {
        $run->update(array_merge($this->emptyCursor(), [
            'status' => CrawlerRunStatus::Completed,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
        ]));

        $this->logger->event("Прогон краулера #{$run->id} завершён.", $this->context($run));
    }

    /** Падение прогона: фиксируем error-блок и backoff, курсор СОХРАНЯЕМ для возможного resume. */
    public function fail(CrawlerRun $run, Throwable $e): void
    {
        $attempts = $run->attempts + 1;

        $run->update([
            'status' => CrawlerRunStatus::Failed,
            'attempts' => $attempts,
            'last_error' => $e->getMessage(),
            'last_error_at' => now(),
            'next_retry_at' => now()->addMinutes($this->backoffMinutes($attempts)),
        ]);

        $this->logger->event(
            "Прогон краулера #{$run->id} упал: {$e->getMessage()}",
            array_merge($this->context($run), [
                'cursor' => $this->cursorSnapshot($run),
                'trace' => $e->getTraceAsString(),
            ]),
        );
    }

    /** Прогон считается оборванным, если по нему давно нет heartbeat. */
    public function isStale(CrawlerRun $run): bool
    {
        $reference = $run->last_heartbeat_at ?? $run->started_at;

        return $reference === null
            || $reference->lt(now()->subMinutes(self::HEARTBEAT_STALE_MINUTES));
    }

    private function recoverOrphan(CrawlerRun $run): CrawlerRun
    {
        $beatAt = $run->last_heartbeat_at ?? $run->started_at;
        $ago = $beatAt?->diffForHumans() ?? 'неизвестно когда';

        $run->update([
            'attempts' => $run->attempts + 1,
            'last_heartbeat_at' => now(),
            'last_error_at' => now(),
            'last_error' => $run->last_error ?? 'Прогон оборван без graceful-завершения (устаревший heartbeat).',
        ]);

        $this->logger->event(
            "Обнаружен оборванный прогон #{$run->id} (heartbeat {$ago}). Продолжаем с сохранённого курсора.",
            array_merge($this->context($run), ['cursor' => $this->cursorSnapshot($run)]),
        );

        return $run;
    }

    /** 2, 4, 8, 16... минут, но не дольше часа. */
    private function backoffMinutes(int $attempts): int
    {
        return (int) min(2 ** $attempts, 60);
    }

    /**
     * @return array<string, null>
     */
    private function emptyCursor(): array
    {
        return array_fill_keys(self::CURSOR_KEYS, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(CrawlerRun $run): array
    {
        return [
            'entity_type' => CrawlerRun::class,
            'entity_id' => $run->id,
            'stage' => 'Crawling',
            'source' => 'crawler',
            'run_id' => $run->id,
            'crawler_id' => $run->crawler_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cursorSnapshot(CrawlerRun $run): array
    {
        return [
            'day' => $run->day_from?->toDateString(),
            'page' => $run->current_page,
            'url' => $run->cursor_url,
            'price_from' => $run->price_from,
            'price_to' => $run->price_to,
        ];
    }
}
