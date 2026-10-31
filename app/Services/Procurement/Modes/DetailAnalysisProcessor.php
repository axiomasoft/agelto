<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\SourceEvent;
use App\Dto\Procurement\Crawler\SourceFailure;
use App\Dto\Procurement\Details\ParsedProcurementDetails;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Exceptions\Procurement\Health\HealthBaselineUnavailableException;
use App\Exceptions\Procurement\Network\AdapterInputRejected;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Contracts\DeadlineAwareSourceMethod;
use App\Services\Procurement\Detail\CommonInfoProcessor;
use App\Services\Procurement\Detail\EventJournalProcessor;
use App\Services\Procurement\Detail\SupplierResultsProcessor;
use App\Services\Procurement\DocumentIndexer;
use App\Services\Procurement\Health\CardAnchorProbe;
use App\Services\Procurement\Health\CardLayoutPreflight;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\Network\AcquisitionEligibility;
use App\Services\Procurement\Network\AdapterHealthPolicy;
use App\Services\Procurement\Network\LegacyResponseAssessment;
use App\Services\Procurement\OperationLogWriter;
use App\Services\Procurement\PlatformAdapterRegistry;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use App\Services\Procurement\SourceControl\SourceEventReporter;
use App\Services\Procurement\SourceMethodFactory;
use App\Support\SecretRedactor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Оркестратор детального анализа закупки (`02-crawling.md` §5): забор карточки (P3.1) →
 * процессоры вкладок в фиксированном порядке common-info → organizations → documents(+download)
 * → event-journal → supplier-results → `detail_fetched_at`. Здесь же собирается исход разбора:
 * только этот шов одновременно держит сырой HTML и результаты парсеров, поэтому зонд не дублирует
 * разбор и не меняет контракт ни одного парсера. Ошибка на любом шаге для ОДНОЙ
 * закупки уходит в error-блок `detail_*` (`01-database.md` §11 «Детальная карточка упала»),
 * а не наружу — вызывающий код (команда/планировщик) продолжает обработку остальных закупок.
 */
final readonly class DetailAnalysisProcessor
{
    public function __construct(
        private SourceMethodFactory $sourceMethodFactory,
        private PlatformAdapterRegistry $adapters,
        private CommonInfoProcessor $commonInfoProcessor,
        private DocumentIndexer $documentIndexer,
        private EventJournalProcessor $eventJournalProcessor,
        private SupplierResultsProcessor $supplierResultsProcessor,
        private HealthBaselineReader $healthBaselineReader,
        private CardAnchorProbe $cardAnchorProbe,
        private ProcurementStoreRepository $procurementStore,
        private OperationLogWriter $operationLogs,
        private CardLayoutPreflight $cardPreflight,
        private AcquisitionEligibility $eligibility,
        private LegacyResponseAssessment $classifier,
        private SourceEventReporter $events,
    ) {}

    public function run(Procurement $procurement, ?float $deadlineAt = null): bool
    {
        if (! config('crawler.downstream_enabled', true)) {
            return false;
        }

        $input = $procurement->detail_parse_outcome['input_validation'] ?? null;
        $inputRetryAt = $procurement->getAttribute('detail_next_retry_at');
        if ($input !== null && ($procurement->detail_failed
            || ($inputRetryAt instanceof CarbonInterface && $inputRetryAt->isFuture()))) {
            return false;
        }

        // Отказ уже передан в `SourceFailureReporter` (скачивание): внешний catch не дублирует.
        $failureReported = false;

        try {
            $registry = $procurement->registry;

            if ($registry === null) {
                throw new RuntimeException("Procurement #{$procurement->id}: registry_id не резолвится в существующий Registry.");
            }

            $sourceMethod = $this->adapters->detailMethod(registry: $registry);

            // Canonical detail eligibility is independent of listing. During cooldown/hold
            // no input attempt is consumed and the procurement remains queued.
            $platformId = $registry->platform_id;

            if (in_array($platformId, $this->eligibility->excludedPlatforms(StageCode::Detail), true)) {
                return false;
            }

            $sourceMethod = $this->sourceMethodFactory->make(
                method: $sourceMethod,
                registry: $registry,
            );

            try {
                $details = $sourceMethod instanceof DeadlineAwareSourceMethod && $deadlineAt !== null
                    ? $sourceMethod->collectDetailsWithinDeadline(
                        registryNumber: $procurement->source_identity_value,
                        registry: $registry,
                        deadlineAt: $deadlineAt,
                    )
                    : $sourceMethod->collectDetails(registryNumber: $procurement->source_identity_value, registry: $registry);
            } catch (OutboundUnavailable|AdapterInputRejected|HealthBaselineUnavailableException $exception) {
                throw $exception;
            } catch (PlatformBudgetExhaustedException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // Typed network dependencies bypass this branch. Surviving logical source
                // exceptions retain public diagnostics and existing business retry behavior.
                $kind = $this->classifier->classifyCardException($exception);
                $this->reportFailure(new SourceFailure(
                    platformId: $platformId,
                    stage: SourceStage::Detail,
                    kind: $kind,
                    detail: "Карточка {$procurement->source_identity_value} не получена: ".mb_substr(SecretRedactor::text($exception->getMessage()), 0, 500),
                    context: ['procurement_id' => $procurement->id, 'exception' => $exception::class],
                ));
                $failureReported = true;

                // Включая 404: один ответ не доказывает окончательное удаление карточки.
                // Повторы ограничены общей политикой попыток и backoff в markFailed().
                throw $exception;
            }

            return DB::transaction(fn (): bool => $this->applyDetails($procurement, $registry, $details));
        } catch (AdapterInputRejected $failure) {
            $registry = $procurement->registry ?? throw new RuntimeException('Input retry registry is missing.');
            $code = $registry->platform?->getAttribute('code');
            if (! is_string($code)) {
                return false;
            }
            $this->procurementStore->recordDetailContractFailure($procurement, $this->adapters->adapterContract($code, 'detail'),
                $failure->checkCode, app(AdapterHealthPolicy::class)->inputLayoutAttempts);
            $this->reportFailure(new SourceFailure(platformId: $registry->platform_id, stage: SourceStage::Detail,
                kind: SourceFailureKind::LayoutDrift, detail: 'Карточка сохранена для повторной проверки: '.$failure->checkCode,
                context: ['procurement_id' => $procurement->id, 'check_code' => $failure->checkCode, 'missing_selectors' => $failure->missingSelectors]));

            return false;
        } catch (OutboundUnavailable|OutboundOwnershipLost|HealthBaselineUnavailableException) {
            return false;
        } catch (PlatformBudgetExhaustedException) {
            $this->operationLogs->budgetExhausted($procurement->id);

            return false;
        } catch (Throwable $exception) {
            if (DatabaseFailureClassifier::isTransient($exception)
                || $exception instanceof DependencyUnavailable) {
                return false;
            }
            $this->operationLogs->detailFailed($procurement->id, $exception);

            if (! $failureReported) {
                // Карточка получена, упал разбор/запись — это наш код: брейкер не считает,
                // карточка пропускается (штатный повтор с backoff), событие ERROR в журнал и лог.
                $this->reportCodeError(procurement: $procurement, exception: $exception);
            }

            $this->markFailed(procurement: $procurement, exception: $exception);

            return false;
        }
    }

    private function applyDetails(Procurement $procurement, Registry $registry, ProcurementDetails $details): bool
    {
        if ($details->validation !== null) {
            app(AdapterHealthRepository::class)->assertReceipt($details->validation);
        }
        $platformId = $registry->platform_id;
        // Проверка вёрстки ДО разбора: нет базовых селекторов — страница не обрабатывается
        // целиком (ни одна вкладка), чтобы не записать «пустые» данные с чужой вёрстки.
        // Попытка карточки не тратится: это проблема площадки, а не закупки; карточка
        // остаётся в очереди, а серия таких отказов уводит этап в ожидание.
        $baseline = $this->healthBaselineReader->read(registry: $registry);
        $parser = $this->adapters->detailParser(registry: $registry);
        $missing = $this->cardPreflight->missingBaseSelectors(details: $details, baseline: $baseline);

        if ($missing !== []) {
            $summary = implode('; ', array_map(
                static fn (string $tab, array $selectors): string => $tab.': '.implode(', ', $selectors),
                array_keys($missing),
                $missing,
            ));
            $this->reportFailure(new SourceFailure(
                platformId: $platformId,
                stage: SourceStage::Detail,
                kind: SourceFailureKind::LayoutDrift,
                detail: "Карточка {$procurement->source_identity_value} не обработана: нет базовых селекторов ({$summary}).",
                context: ['procurement_id' => $procurement->id, 'missing_selectors' => $missing],
            ));

            return false;
        }

        $commonInfo = null;
        $commonInfoSnapshotStatus = null;
        $partiesComplete = false;
        $parties = [];
        $documents = [];
        $documentsSnapshotStatus = null;
        $journalEntries = [];
        $supplierResults = null;

        $parsed = $details->parsed ?? new ParsedProcurementDetails(
            $details->commonInfoHtml === null ? null : $parser->commonInfo($details->commonInfoHtml),
            $details->documentsHtml === null ? null : $parser->documents($details->documentsHtml),
            $details->eventJournalHtml === null ? null : $parser->eventJournal($details->eventJournalHtml),
            $details->supplierResultsHtml === null ? null : $parser->supplierResults($details->supplierResultsHtml));

        if ($parsed->commonInfo !== null) {
            $commonInfoSnapshot = $parsed->commonInfo;
            $parties = $commonInfoSnapshot->parties;
            $commonInfo = $commonInfoSnapshot->commonInfo;
            $commonInfoSnapshotStatus = $commonInfoSnapshot->status->value;
            $partiesComplete = $commonInfoSnapshot->authorizesPartiesReconciliation();

            $this->commonInfoProcessor->process(procurement: $procurement, snapshot: $commonInfoSnapshot);
        } else {
            Log::warning(message: 'DetailAnalysisProcessor: вкладка common-info не снята.', context: ['procurement_id' => $procurement->id]);
        }

        if ($parsed->documents !== null) {
            $documentsSnapshot = $parsed->documents;
            $this->documentIndexer->index(procurement: $procurement, snapshot: $documentsSnapshot);
            $documents = $documentsSnapshot->documents;
            $documentsSnapshotStatus = $documentsSnapshot->status->value;
        } else {
            Log::warning(message: 'DetailAnalysisProcessor: вкладка documents не снята.', context: ['procurement_id' => $procurement->id]);
        }

        if ($parsed->journalEntries !== null) {
            $journalEntries = $parsed->journalEntries;
            $this->eventJournalProcessor->process($procurement, $journalEntries);
        } else {
            Log::warning(message: 'DetailAnalysisProcessor: вкладка event-journal не снята.', context: ['procurement_id' => $procurement->id]);
        }

        if ($parsed->supplierResults !== null) {
            $supplierResults = $parsed->supplierResults;
            $this->supplierResultsProcessor->process($procurement, $supplierResults);
        }

        $detailParseOutcome = $this->detailParseOutcome(
            details: $details,
            baseline: $baseline,
            keyFieldValues: [
                'common-info' => [
                    'snapshot_status' => $commonInfoSnapshotStatus,
                    'contact_person_name' => $commonInfo?->contactPersonName,
                    'contact_person_email' => $commonInfo?->contactPersonEmail,
                    'financing_rows' => $commonInfo?->financingRows,
                    'items' => $commonInfo?->items,
                    'parties' => $parties,
                    'parties_complete' => $partiesComplete,
                ],
                'documents' => [
                    'documents' => $documents,
                    'snapshot_status' => $documentsSnapshotStatus,
                ],
                'event-journal' => ['entries' => $journalEntries],
                'supplier-results' => [
                    'protocol' => $supplierResults?->protocol,
                    'bids' => $supplierResults?->bids,
                    'award' => $supplierResults?->award,
                ],
            ],
        );

        if ($commonInfoSnapshotStatus !== 'complete' || $documentsSnapshotStatus !== 'complete' || ! $partiesComplete) {
            $this->markFailed(
                procurement: $procurement,
                exception: new RuntimeException('Required detail snapshots are incomplete.'),
                detailParseOutcome: $detailParseOutcome,
            );

            return false;
        }

        $this->procurementStore->markDetailFetched(
            procurement: $procurement,
            fetchedAt: now(),
            detailParseOutcome: $detailParseOutcome,
        );

        return true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $keyFieldValues
     * @return array<string, array<string, mixed>>|null
     */
    private function detailParseOutcome(ProcurementDetails $details, array $keyFieldValues, HealthBaseline $baseline): ?array
    {
        if (! config('crawler.health.enabled')) {
            return null;
        }

        $tabs = [
            'common-info' => $details->commonInfoHtml,
            'documents' => $details->documentsHtml,
            'event-journal' => $details->eventJournalHtml,
            'supplier-results' => $details->supplierResultsHtml,
        ];

        $baselineTabs = $baseline->cardTabs;

        $outcomes = [];

        foreach ($tabs as $tab => $html) {
            $outcomes[$tab] = $this->probeTab(
                html: $html,
                tabDescription: $baselineTabs[$tab] ?? [],
                keyFieldValues: $keyFieldValues[$tab] ?? [],
            );
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $tabDescription
     * @param  array<string, mixed>  $keyFieldValues
     * @return array<string, mixed>
     */
    private function probeTab(?string $html, array $tabDescription, array $keyFieldValues): array
    {
        try {
            return $this->cardAnchorProbe
                ->probe(html: $html, tabDescription: $tabDescription, keyFieldValues: $keyFieldValues)
                ->toPayload();
        } catch (Throwable) {
            return $this->cardAnchorProbe
                ->probeFailed(tabDescription: $tabDescription, keyFieldValues: $keyFieldValues)
                ->toPayload();
        }
    }

    private function reportFailure(SourceFailure $failure): void
    {
        $event = SourceEvent::forFailure(
            CrawlerEventCode::forFailure($failure->stage, $failure->kind),
            $failure, $failure->detail,
        );
        if ($failure->kind === SourceFailureKind::NotFound) {
            $this->events->log($event);
        } else {
            $this->events->report($event);
        }
    }

    private function reportCodeError(Procurement $procurement, Throwable $exception): void
    {
        $platformId = $procurement->registry?->platform_id;

        if ($platformId === null) {
            return;
        }

        $this->reportFailure(new SourceFailure(
            platformId: $platformId,
            stage: SourceStage::Detail,
            kind: SourceFailureKind::CodeError,
            detail: "Карточка {$procurement->source_identity_value} пропущена: ".mb_substr(SecretRedactor::text($exception->getMessage()), 0, 500),
            context: ['procurement_id' => $procurement->id, 'exception' => $exception::class],
        ));
    }

    /** @param array<string, mixed>|null $detailParseOutcome */
    private function markFailed(Procurement $procurement, Throwable $exception, ?array $detailParseOutcome = null): void
    {
        $attempts = $procurement->detail_attempts + 1;
        $maxAttempts = $this->integerConfig(key: 'crawler.detail_max_attempts', default: 5);
        $backoffMinutes = $this->integerConfig(key: 'crawler.detail_retry_backoff_minutes', default: 15);
        $failed = $attempts >= $maxAttempts;

        $this->procurementStore->markDetailFailed(
            procurement: $procurement,
            error: SecretRedactor::text($exception->getMessage()),
            nextRetryAt: $failed ? null : now()->addMinutes($backoffMinutes * $attempts),
            failed: $failed,
            detailParseOutcome: $detailParseOutcome,
        );

        Log::warning(message: 'DetailAnalysisProcessor: снятие детального слоя упало.', context: [
            'procurement_id' => $procurement->id,
            'attempts' => $attempts,
            'error' => SecretRedactor::text($exception->getMessage()),
        ]);
    }

    public function recordFailedJob(int $procurementId, string $error): void
    {
        $procurement = Procurement::query()->find($procurementId);

        if ($procurement === null || $procurement->detail_failed || $procurement->detail_fetched_at !== null) {
            return;
        }

        $this->markFailed(procurement: $procurement, exception: new RuntimeException($error));
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new RuntimeException("{$key} must be an integer.");
        }

        return $value;
    }
}
