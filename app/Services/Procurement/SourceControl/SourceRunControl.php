<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\SourceEvent;
use App\Dto\Procurement\Crawler\SourceFailure;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Crawler\SourceStageStatus;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;
use App\Services\Procurement\CrawlerRunManager;
use App\Support\SecretRedactor;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * Связка «прогон листинга ↔ брейкер этапа листинга» для fresh-процессора и архивного сканера.
 * Отказы не учитывает и не публикует сам — передаёт их в `SourceFailureReporter`; здесь только
 * то, что касается прогона: пауза прогона и событие о ней.
 *
 * - `admit()` перед блоком: этап ждёт или на ручной паузе → прогон в `paused` с меткой
 *   `source_suspended`, курсор не тронут (поднимет `crawler:source-circuits` или оператор).
 * - `afterBlock()` / `onBlockException()` — любой отказ приостанавливает прогон на том же
 *   блоке (`true`). До порога брейкера ждёт только этот прогон, после — весь этап.
 *   Успешно записанные строки можно повторить идемпотентно; потерянные строки пропускать нельзя.
 *
 * Инвариант: эти методы вызываются ВНЕ транзакции блока. Так инцидент и счётчик брейкера
 * сохраняются, даже если запись блока откатилась (например, аренда прогона перехвачена).
 */
final readonly class SourceRunControl
{
    public const string StatsFlag = 'source_suspended';

    public const string StopReason = 'source_circuit_open';

    public const string InterruptedReason = 'interrupted';

    public function __construct(
        private SourceCircuitBreaker $breaker,
        private SourceCircuitRepository $circuits,
        private SourceFailureClassifier $classifier,
        private SourceFailureReporter $failures,
        private SourceEventReporter $events,
        private CrawlerRunManager $runs,
    ) {}

    public function admit(Crawler $crawler, Run $run): bool
    {
        $retryAt = $run->stats?->get('source_retry_at');

        if (is_string($retryAt) && Carbon::parse($retryAt)->isFuture()) {
            $this->runs->pause(run: $run, reason: self::StopReason);

            return false;
        }

        if ($this->breaker->admits($this->platformIdFor($crawler), SourceStage::Listing)) {
            if ($run->stats?->get(self::StatsFlag) === true) {
                $stats = $run->stats->toArray();
                unset($stats[self::StatsFlag], $stats['source_retry_at']);

                if (! $this->runs->updateStats(run: $run, stats: $stats)) {
                    throw ExecutionLeaseLost::noLongerOwned();
                }

                $this->events->report(new SourceEvent(
                    code: CrawlerEventCode::RunResumed,
                    platformId: $this->platformIdFor($crawler),
                    message: "Прогон #{$run->id} продолжает обработку сохранённого блока.",
                    crawlerId: $crawler->id,
                    runId: $run->id,
                    actor: SourceCircuitBreaker::SystemActor,
                ));
            }

            return true;
        }

        $this->suspend(crawler: $crawler, run: $run);

        return false;
    }

    /**
     * @param  array<string, mixed>  $requestParams
     * @return bool `true` — прогон приостановлен, вызывающий останавливается
     */
    public function afterBlock(Crawler $crawler, Run $run, FetchResult $result, array $requestParams): bool
    {
        if ($result->failure === null) {
            $this->failures->reportSuccess($this->platformIdFor($crawler), SourceStage::Listing);

            return false;
        }

        $kind = $result->failure;

        return $this->onFailure($crawler, $run, new SourceFailure(
            platformId: $this->platformIdFor($crawler),
            stage: SourceStage::Listing,
            kind: $kind,
            detail: 'Блок не завершён, курсор сохранён: '.$kind->label().'.'.($result->failureDetail === null ? '' : " {$result->failureDetail}"),
            crawlerId: $crawler->id,
            runId: $run->id,
            fetchId: $result->fetchId,
            httpStatus: $result->httpStatus,
            cursor: $this->cursorOf($run),
            requestParams: $requestParams === [] ? null : $requestParams,
        ));
    }

    /**
     * Исключение при скачивании или записи блока. Любое (сеть, прокси, баг нашего кода, БД)
     * классифицируется и решается реакцией из конфига — прогон сам не падает. Пробрасываются
     * только сигналы управления: аренду перехватили (продолжит новый владелец) и бюджет
     * площадки исчерпан (штатный checkpoint).
     *
     * @param  array<string, mixed>  $requestParams
     * @return bool `true` — прогон приостановлен, вызывающий останавливается
     */
    public function onBlockException(Crawler $crawler, Run $run, Throwable $exception, array $requestParams): bool
    {
        if ($exception instanceof ExecutionLeaseLost || $exception instanceof PlatformBudgetExhaustedException
            || $exception instanceof OutboundRequestDeadlineExceeded) {
            throw $exception;
        }

        $kind = $this->classifier->classifyListingException($exception);

        return $this->onFailure($crawler, $run, new SourceFailure(
            platformId: $this->platformIdFor($crawler),
            stage: SourceStage::Listing,
            kind: $kind,
            detail: 'Блок не завершён, курсор сохранён: '.$kind->label().'. '.mb_substr(SecretRedactor::text($exception->getMessage()), 0, 500),
            crawlerId: $crawler->id,
            runId: $run->id,
            cursor: $this->cursorOf($run),
            requestParams: $requestParams === [] ? null : $requestParams,
            context: ['exception' => $exception::class],
        ));
    }

    /**
     * Последняя страховка процессоров прогона: сюда доходят только сбои вне обработки блока
     * (БД недоступна, адаптер площадки не собрался). Прогон не проваливается, а встаёт на
     * паузу с тем же курсором (данные и позиция целы) и продолжится следующим запуском;
     * событие `CRAWLER_RUN_INTERRUPTED` (CRITICAL). Перехват аренды — не сбой: молча выходим.
     */
    public function interrupted(Run $run, Throwable $exception): void
    {
        if ($exception instanceof ExecutionLeaseLost) {
            return;
        }

        if (! $this->runs->pause(run: $run, reason: self::InterruptedReason, error: mb_substr(SecretRedactor::text($exception->getMessage()), 0, 500))) {
            return;
        }

        $registry = $run->crawler?->registry;

        if (! $registry instanceof Registry) {
            return;
        }

        $this->events->report(new SourceEvent(
            code: CrawlerEventCode::RunInterrupted,
            platformId: $registry->platform_id,
            message: "Прогон #{$run->id} прерван вне обработки блока и поставлен на паузу: ".mb_substr(SecretRedactor::text($exception->getMessage()), 0, 500),
            stage: SourceStage::Listing,
            crawlerId: $run->crawler_id,
            runId: $run->id,
            cursor: $this->cursorOf($run),
            context: ['exception' => $exception::class],
            actor: SourceCircuitBreaker::SystemActor,
        ));
    }

    /** Прогон в паузу источника. Курсор не трогается: продолжим с того же блока. */
    public function suspend(Crawler $crawler, Run $run): void
    {
        $circuit = $this->circuits->forStage($this->platformIdFor($crawler), SourceStage::Listing);
        // Тик планировщика при ожидании/остановке снова доходит до `admit()` с тем же прогоном:
        // повторная запись «приостановлен» на каждый тик забивала бы журнал (найдено сценарием).
        $alreadySuspended = $run->stats?->get(self::StatsFlag) === true;
        $retryAt = match ($circuit->status()) {
            SourceStageStatus::Waiting => $circuit->retry_at,
            SourceStageStatus::Running => now()->addMinutes($this->breaker->effectivePolicy($circuit)->retryIntervalMinutes),
            default => null,
        };

        $paused = $this->runs->pause(run: $run, reason: self::StopReason, extraStats: [
            self::StatsFlag => true,
            'source_retry_at' => $retryAt?->toIso8601String(),
        ]);

        if (! $paused || $alreadySuspended) {
            return;
        }

        $this->events->report(new SourceEvent(
            code: CrawlerEventCode::RunSuspended,
            platformId: $circuit->platform_id,
            message: $retryAt === null
                ? "Прогон #{$run->id} приостановлен: листинг на паузе или остановлен до решения оператора."
                : "Прогон #{$run->id} повторит текущий блок после {$retryAt->toIso8601String()}.",
            stage: SourceStage::Listing,
            crawlerId: $crawler->id,
            runId: $run->id,
            cursor: $this->cursorOf($run),
            actor: SourceCircuitBreaker::SystemActor,
        ));
    }

    private function onFailure(Crawler $crawler, Run $run, SourceFailure $failure): bool
    {
        $this->failures->report($failure);

        // Порог брейкера ограничивает весь этап, но не разрешает терять отдельный блок.
        // До порога ждёт только этот прогон; после порога — все прогоны этапа.
        $this->suspend(crawler: $crawler, run: $run);

        return true;
    }

    private function platformIdFor(Crawler $crawler): int
    {
        $registry = $crawler->registry;

        if (! $registry instanceof Registry) {
            throw new LogicException("Профиль #{$crawler->id} не связан с реестром: площадку брейкера не определить.");
        }

        return $registry->platform_id;
    }

    /** @return array<string, mixed> */
    private function cursorOf(Run $run): array
    {
        $cursor = [];

        foreach ($run->cursor?->toArray() ?? [] as $key => $value) {
            if (is_string($key)) {
                $cursor[$key] = $value;
            }
        }

        return $cursor;
    }
}
