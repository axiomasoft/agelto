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
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;
use App\Services\Procurement\CrawlerRunManager;
use LogicException;
use Throwable;

/**
 * Связка «прогон листинга ↔ брейкер этапа листинга» для fresh-процессора и архивного сканера.
 * Отказы не учитывает и не публикует сам — передаёт их в `SourceFailureReporter`; здесь только
 * то, что касается прогона: пауза прогона и событие о ней.
 *
 * - `admit()` перед блоком: этап ждёт или на ручной паузе → прогон в `paused` с меткой
 *   `source_suspended`, курсор не тронут (поднимет `crawler:source-circuits` или оператор).
 * - `afterBlock()` / `onBlockException()` — после блока: успех, ошибки строк (блок принят),
 *   локальный отказ (инцидент, вызывающий пропускает блок) или уход этапа в ожидание/
 *   остановку (`true`: вызывающий останавливается, блок повторится после возобновления).
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
        if ($this->breaker->admits($this->platformIdFor($crawler), SourceStage::Listing)) {
            return true;
        }

        $this->suspend(crawler: $crawler, run: $run);

        return false;
    }

    /**
     * @param  array<string, mixed>  $requestParams
     * @return bool `true` — этап перестал принимать запросы, вызывающий останавливается
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
            detail: ($kind->skipsBlock() ? 'Блок пропущен: ' : 'Блок принят с ошибками строк: ').$kind->label().'.'.($result->failureDetail === null ? '' : " {$result->failureDetail}"),
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
     * @return bool `true` — этап перестал принимать запросы, вызывающий останавливается
     */
    public function onBlockException(Crawler $crawler, Run $run, Throwable $exception, array $requestParams): bool
    {
        if ($exception instanceof ExecutionLeaseLost || $exception instanceof PlatformBudgetExhaustedException) {
            throw $exception;
        }

        $kind = $this->classifier->classifyListingException($exception);

        return $this->onFailure($crawler, $run, new SourceFailure(
            platformId: $this->platformIdFor($crawler),
            stage: SourceStage::Listing,
            kind: $kind,
            detail: 'Блок пропущен: '.$kind->label().'. '.mb_substr($exception->getMessage(), 0, 500),
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

        $this->runs->pause(run: $run, reason: self::InterruptedReason, extraStats: ['last_error' => mb_substr($exception->getMessage(), 0, 500)]);

        $registry = $run->crawler?->registry;

        if (! $registry instanceof Registry) {
            return;
        }

        $this->events->report(new SourceEvent(
            code: CrawlerEventCode::RunInterrupted,
            platformId: $registry->platform_id,
            message: "Прогон #{$run->id} прерван вне обработки блока и поставлен на паузу: ".mb_substr($exception->getMessage(), 0, 500),
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
        $retryAt = $circuit->status() === SourceStageStatus::Waiting ? $circuit->retry_at : null;

        $this->runs->pause(run: $run, reason: self::StopReason, extraStats: [
            self::StatsFlag => true,
            'source_retry_at' => $retryAt?->toIso8601String(),
        ]);

        if ($alreadySuspended) {
            return;
        }

        $this->events->report(new SourceEvent(
            code: CrawlerEventCode::RunSuspended,
            platformId: $circuit->platform_id,
            message: $retryAt === null
                ? "Прогон #{$run->id} приостановлен: листинг на паузе или остановлен до решения оператора."
                : "Прогон #{$run->id} приостановлен до {$retryAt->toIso8601String()}: листинг в ожидании.",
            stage: SourceStage::Listing,
            crawlerId: $crawler->id,
            runId: $run->id,
            cursor: $this->cursorOf($run),
            actor: SourceCircuitBreaker::SystemActor,
        ));
    }

    private function onFailure(Crawler $crawler, Run $run, SourceFailure $failure): bool
    {
        if ($this->failures->report($failure)) {
            return false;
        }

        // Блок не пропускается: курсор стоит, после ожидания этот же блок запросится снова.
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
