<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\FetchResult;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use Throwable;

/**
 * Связка «прогон листинга ↔ брейкер этапа листинга» для fresh-процессора и архивного сканера.
 *
 * - `admit()` перед блоком: этап ждёт или на ручной паузе → прогон в `paused` с меткой
 *   `source_suspended`, курсор не тронут (поднимет `crawler:source-circuits` или оператор).
 * - `afterBlock()` / `onTransportFailure()` — после блока: успех, ошибки строк (блок принят),
 *   локальный отказ (инцидент, вызывающий пропускает блок) или уход этапа в ожидание
 *   (`true`: вызывающий останавливается, блок повторится после ожидания).
 *
 * Инвариант: эти методы вызываются ВНЕ транзакции блока. Так инцидент и счётчик брейкера
 * сохраняются, даже если запись блока откатилась (например, аренда прогона перехвачена).
 */
final readonly class SourceRunControl
{
    public const string StatsFlag = 'source_suspended';

    public const string StopReason = 'source_circuit_open';

    public function __construct(
        private SourceCircuitController $circuit,
        private ListingFailureClassifier $classifier,
        private SourceIncidentRecorder $incidents,
        private CrawlerRunManager $runs,
    ) {}

    public function admit(Crawler $crawler, Run $run): bool
    {
        if ($this->circuit->admits($this->circuit->platformIdFor($crawler), SourceStage::Listing)) {
            return true;
        }

        $this->suspend(crawler: $crawler, run: $run);

        return false;
    }

    /**
     * @param  array<string, mixed>  $requestParams
     */
    public function afterBlock(Crawler $crawler, Run $run, FetchResult $result, array $requestParams): bool
    {
        $platformId = $this->circuit->platformIdFor($crawler);

        if ($result->failure === null) {
            $this->circuit->recordSuccess($platformId, SourceStage::Listing);

            return false;
        }

        return $this->onFailure(
            crawler: $crawler,
            run: $run,
            kind: $result->failure,
            detail: $result->failureDetail,
            requestParams: $requestParams,
            httpStatus: $result->httpStatus,
            fetchId: $result->fetchId,
        );
    }

    /**
     * Исключение при скачивании блока. Не сетевой отказ (ошибка кода/БД) пробрасывается: его
     * нельзя превращать в «пропуск блока».
     *
     * @param  array<string, mixed>  $requestParams
     */
    public function onTransportFailure(Crawler $crawler, Run $run, Throwable $exception, array $requestParams): bool
    {
        $kind = $this->classifier->classifyException($exception) ?? throw $exception;

        return $this->onFailure(
            crawler: $crawler,
            run: $run,
            kind: $kind,
            detail: mb_substr($exception->getMessage(), 0, 500),
            requestParams: $requestParams,
        );
    }

    /** Прогон в паузу источника. Курсор не трогается: продолжим с того же блока. */
    public function suspend(Crawler $crawler, Run $run): void
    {
        $circuit = $this->circuit->circuitFor($this->circuit->platformIdFor($crawler), SourceStage::Listing);
        $retryAt = $circuit->manually_paused ? null : $circuit->retry_at;

        $this->runs->pause(run: $run, reason: self::StopReason, extraStats: [
            self::StatsFlag => true,
            'source_retry_at' => $retryAt?->toIso8601String(),
        ]);

        $this->incidents->record(
            platformId: $circuit->platform_id,
            type: SourceIncidentType::RunSuspended,
            message: $retryAt === null
                ? "Прогон #{$run->id} приостановлен: листинг на ручной паузе."
                : "Прогон #{$run->id} приостановлен до {$retryAt->toIso8601String()}: листинг в ожидании.",
            crawlerId: $crawler->id,
            runId: $run->id,
            cursor: $this->cursorOf($run),
            actor: SourceCircuitController::SystemActor,
        );
    }

    /** @param array<string, mixed> $requestParams */
    private function onFailure(
        Crawler $crawler,
        Run $run,
        SourceFailureKind $kind,
        ?string $detail,
        array $requestParams,
        ?int $httpStatus = null,
        ?int $fetchId = null,
    ): bool {
        $platformId = $this->circuit->platformIdFor($crawler);
        $suspended = $this->circuit->recordFailure($platformId, SourceStage::Listing, $kind, ['run_id' => $run->id]);

        if ($suspended) {
            // Блок не пропускается: курсор стоит, после ожидания этот же блок запросится снова.
            $this->suspend(crawler: $crawler, run: $run);

            return true;
        }

        $this->incidents->record(
            platformId: $platformId,
            type: $kind->skipsBlock() ? SourceIncidentType::BlockSkipped : SourceIncidentType::BlockDegraded,
            message: ($kind->skipsBlock() ? 'Блок пропущен: ' : 'Блок принят с ошибками строк: ').$kind->label().'.'.($detail === null ? '' : " {$detail}"),
            crawlerId: $crawler->id,
            runId: $run->id,
            fetchId: $fetchId,
            failureKind: $kind,
            httpStatus: $httpStatus,
            cursor: $this->cursorOf($run),
            requestParams: $requestParams === [] ? null : $requestParams,
            actor: SourceCircuitController::SystemActor,
        );

        return false;
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
