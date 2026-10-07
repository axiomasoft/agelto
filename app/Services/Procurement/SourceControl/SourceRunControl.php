<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\SourceCircuitState;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Связка «прогон ↔ брейкер источника» для процессоров обхода (fresh, archive-сканер):
 *
 * - `admit()` перед каждым блоком: открытый брейкер или ручная пауза → прогон в `paused`
 *   с меткой `source_suspended` (его поднимет `crawler:source-circuits` или оператор);
 * - `onTransportFailure()` — отказ без HTTP-ответа (нет прокси, обрыв): классифицируется и
 *   учитывается брейкером так же, как отказ блока с ответом.
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
        $decision = $this->circuit->gate(crawler: $crawler, run: $run);

        if ($decision->allowed) {
            return true;
        }

        $this->suspend(crawler: $crawler, run: $run, retryAt: $decision->retryAt);

        return false;
    }

    /**
     * Прогон в паузу источника. Курсор не трогается: следующий запуск начнёт с того же блока.
     */
    public function suspend(Crawler $crawler, Run $run, ?CarbonImmutable $retryAt = null): void
    {
        $retryAt ??= $this->circuit->circuitFor($this->circuit->platformIdFor($crawler))->retry_at?->toImmutable();

        $this->runs->pause(run: $run, reason: self::StopReason, extraStats: [
            self::StatsFlag => true,
            'source_retry_at' => $retryAt?->toIso8601String(),
        ]);

        $this->incidents->record(
            platformId: $this->circuit->platformIdFor($crawler),
            type: SourceIncidentType::RunSuspended,
            message: $retryAt === null
                ? "Прогон #{$run->id} приостановлен: источник на ручной паузе."
                : "Прогон #{$run->id} приостановлен до {$retryAt->toIso8601String()}: источник на паузе.",
            crawlerId: $crawler->id,
            runId: $run->id,
            cursor: $this->cursorOf($run),
            actor: SourceCircuitController::SystemActor,
        );
    }

    /**
     * Отказ транспорта при заборе блока. `true` — брейкер открыт, прогон уже приостановлен;
     * `false` — локальный отказ записан, вызывающий пропускает блок. Неклассифицируемое
     * исключение (ошибка кода/БД) пробрасывается как есть.
     *
     * @param  array<string, mixed>  $requestParams
     */
    public function onTransportFailure(Crawler $crawler, Run $run, Throwable $exception, array $requestParams = []): bool
    {
        $kind = $this->classifier->classifyException($exception) ?? throw $exception;

        $state = $this->circuit->recordFailure(
            crawler: $crawler,
            run: $run,
            kind: $kind,
            cursor: $this->cursorOf($run),
            requestParams: $requestParams === [] ? null : $requestParams,
            detail: mb_substr($exception->getMessage(), 0, 500),
        );

        if ($state !== SourceCircuitState::Open) {
            return false;
        }

        $this->suspend(crawler: $crawler, run: $run);

        return true;
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
