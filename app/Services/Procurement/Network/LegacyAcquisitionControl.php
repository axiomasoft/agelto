<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\SourceEvent;
use App\Dto\Procurement\Crawler\SourceFailure;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\PlatformAdapterRegistry;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use App\Services\Procurement\SourceControl\SourceEventReporter;
use App\Support\SecretRedactor;
use LogicException;
use Throwable;

/**
 * Existing listing run boundary after the canonical network cutover. Reads acquisition
 * eligibility, preserves the cursor and atomically pauses/releases the existing run owner.
 * Network deferrals retain their absolute RetryAt without consuming the input retry budget.
 * Input failures use the run's bounded retry history; health mutations belong to the
 * canonical transport/adapter repositories. Public source diagnostics remain available.
 */
final readonly class LegacyAcquisitionControl
{
    public const string StatsFlag = 'source_suspended';

    public const string StopReason = 'outbound_dependency';

    public const string InterruptedReason = 'interrupted';

    public function __construct(
        private AcquisitionEligibility $eligibility,
        private LegacyResponseAssessment $classifier,
        private SourceEventReporter $events,
        private CrawlerRunManager $runs,
    ) {}

    public function admit(Crawler $crawler, Run $run): bool
    {
        if (in_array($this->platformIdFor($crawler), $this->eligibility->excludedPlatforms(StageCode::Listing), true)) {
            $this->suspend(crawler: $crawler, run: $run);

            return false;
        }

        if ($run->stats?->get(self::StatsFlag) === true) {
            $stats = $run->stats->toArray();
            unset($stats[self::StatsFlag], $stats['source_retry_at']);
            if (! $this->runs->updateStats(run: $run, stats: $stats)) {
                throw ExecutionLeaseLost::noLongerOwned();
            }
            $this->events->report(new SourceEvent(code: CrawlerEventCode::RunResumed,
                platformId: $this->platformIdFor($crawler), message: "Прогон #{$run->id} продолжает сохранённый блок.",
                crawlerId: $crawler->id, runId: $run->id, actor: 'system'));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $requestParams
     * @return bool `true` — прогон приостановлен, вызывающий останавливается
     */
    public function afterBlock(Crawler $crawler, Run $run, FetchResult $result, array $requestParams): bool
    {
        if ($result->failure === null) {
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
     * сохраняет позицию прогона и классифицируется для публичной диагностики. Пробрасываются
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

        if (DatabaseFailureClassifier::isTransient($exception)
            || $exception instanceof DependencyUnavailable
            || $exception instanceof OutboundOwnershipLost) {
            $this->suspend($crawler, $run);

            return true;
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
        ), $exception instanceof OutboundUnavailable && $exception->outcome instanceof TransportDeferred
            ? $exception->outcome->retryAt->value : null);
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
            actor: 'system',
        ));
    }

    /** Прогон в паузу источника. Курсор не трогается: продолжим с того же блока. */
    public function suspend(Crawler $crawler, Run $run, ?\DateTimeInterface $retryAt = null): void
    {
        $alreadySuspended = $run->stats?->get(self::StatsFlag) === true;
        $paused = $this->runs->pause(run: $run, reason: 'outbound_dependency', extraStats: [self::StatsFlag => true], retryAt: $retryAt);
        if ($paused && ! $alreadySuspended) {
            $this->events->report(new SourceEvent(code: CrawlerEventCode::RunSuspended,
                platformId: $this->platformIdFor($crawler), message: "Прогон #{$run->id} приостановлен на сохранённом блоке.",
                stage: SourceStage::Listing, crawlerId: $crawler->id, runId: $run->id,
                cursor: $this->cursorOf($run), actor: 'system'));
        }
    }

    private function onFailure(Crawler $crawler, Run $run, SourceFailure $failure, ?\DateTimeInterface $retryAt = null): bool
    {
        $event = SourceEvent::forFailure(
            CrawlerEventCode::forFailure($failure->stage, $failure->kind), $failure, $failure->detail,
        );
        if ($failure->kind === SourceFailureKind::NotFound) {
            $this->events->log($event);
        } else {
            $this->events->report($event);
        }

        if (in_array($failure->kind, [SourceFailureKind::LayoutDrift,
            SourceFailureKind::InvalidContent, SourceFailureKind::EmptyBody,
            SourceFailureKind::RowErrors, SourceFailureKind::CodeError], true)) {
            $registry = $crawler->registry ?? throw new LogicException('Input retry platform is missing.');
            $code = $registry->platform?->getAttribute('code');
            if (! is_string($code)) {
                throw new LogicException('Input retry platform code is missing.');
            }
            $contract = app(PlatformAdapterRegistry::class)->adapterContract($code, 'listing');
            $policy = app(AdapterHealthPolicy::class);
            $limit = in_array($failure->kind, [SourceFailureKind::LayoutDrift,
                SourceFailureKind::EmptyBody], true) ? $policy->inputLayoutAttempts : $policy->inputFaultAttempts;
            $fingerprint = hash('sha256', json_encode([$contract->toArray(), $this->cursorOf($run)], JSON_THROW_ON_ERROR));
            $this->runs->recordInputFailure($run, $fingerprint, $limit);
            $this->events->report(new SourceEvent(code: CrawlerEventCode::RunSuspended,
                platformId: $this->platformIdFor($crawler), message: "Прогон #{$run->id} ожидает повторной проверки сохранённого входа.",
                stage: SourceStage::Listing, crawlerId: $crawler->id, runId: $run->id, cursor: $this->cursorOf($run), actor: 'system'));

            return true;
        }
        $this->suspend(crawler: $crawler, run: $run, retryAt: $retryAt);

        return true;
    }

    private function platformIdFor(Crawler $crawler): int
    {
        $registry = $crawler->registry;

        if (! $registry instanceof Registry) {
            throw new LogicException("Профиль #{$crawler->id} не связан с реестром: площадку допуска не определить.");
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
