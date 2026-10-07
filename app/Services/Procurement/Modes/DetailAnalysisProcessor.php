<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\SourceFailure;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Contracts\DeadlineAwareSourceMethod;
use App\Services\Procurement\Detail\CommonInfoProcessor;
use App\Services\Procurement\Detail\EventJournalProcessor;
use App\Services\Procurement\Detail\SupplierResultsProcessor;
use App\Services\Procurement\DocumentIndexer;
use App\Services\Procurement\Eis\Card\CommonInfoParser;
use App\Services\Procurement\Eis\Card\DocumentsParser;
use App\Services\Procurement\Eis\Card\EventJournalParser;
use App\Services\Procurement\Eis\Card\PartiesParser;
use App\Services\Procurement\Eis\Card\SupplierResultsParser;
use App\Services\Procurement\Health\CardAnchorProbe;
use App\Services\Procurement\Health\CardLayoutPreflight;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\OperationLogWriter;
use App\Services\Procurement\SourceControl\SourceCircuitBreaker;
use App\Services\Procurement\SourceControl\SourceFailureClassifier;
use App\Services\Procurement\SourceControl\SourceFailureReporter;
use App\Services\Procurement\SourceMethodFactory;
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
        private CommonInfoParser $commonInfoParser,
        private CommonInfoProcessor $commonInfoProcessor,
        private PartiesParser $partiesParser,
        private DocumentsParser $documentsParser,
        private DocumentIndexer $documentIndexer,
        private EventJournalParser $eventJournalParser,
        private EventJournalProcessor $eventJournalProcessor,
        private SupplierResultsParser $supplierResultsParser,
        private SupplierResultsProcessor $supplierResultsProcessor,
        private HealthBaselineReader $healthBaselineReader,
        private CardAnchorProbe $cardAnchorProbe,
        private ProcurementStoreRepository $procurementStore,
        private OperationLogWriter $operationLogs,
        private CardLayoutPreflight $cardPreflight,
        private SourceCircuitBreaker $breaker,
        private SourceFailureClassifier $classifier,
        private SourceFailureReporter $failures,
    ) {}

    public function run(Procurement $procurement, ?float $deadlineAt = null): bool
    {
        // Отказ уже передан в `SourceFailureReporter` (скачивание): внешний catch не дублирует.
        $failureReported = false;

        try {
            $registry = $procurement->registry;

            if ($registry === null) {
                throw new RuntimeException("Procurement #{$procurement->id}: registry_id не резолвится в существующий Registry.");
            }

            $sourceMethod = config('crawler.detail_source_method', SourceMethodEnum::Csv->value);

            if (! is_string($sourceMethod)) {
                throw new RuntimeException('crawler.detail_source_method must be a string.');
            }

            // Брейкер этапа карточек (независим от листинга). Этап ждёт или на ручной паузе —
            // карточку не трогаем вовсе: попытка не тратится, закупка остаётся в очереди.
            $platformId = $registry->platform_id;

            if (! $this->breaker->admits($platformId, SourceStage::Detail)) {
                return false;
            }

            $sourceMethod = $this->sourceMethodFactory->make(
                method: SourceMethodEnum::from($sourceMethod),
                registry: $registry,
            );

            try {
                $details = $sourceMethod instanceof DeadlineAwareSourceMethod && $deadlineAt !== null
                    ? $sourceMethod->collectDetailsWithinDeadline(
                        registryNumber: $procurement->registry_number,
                        registry: $registry,
                        deadlineAt: $deadlineAt,
                    )
                    : $sourceMethod->collectDetails(registryNumber: $procurement->registry_number, registry: $registry);
            } catch (PlatformBudgetExhaustedException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // Вид отказа решает классификатор: сеть/прокси/чужая страница (уже после штатных
                // ретраев и ротации прокси) считаются брейкером, ошибка нашего кода — нет.
                // Карточка в любом случае уходит в штатный повтор с backoff (`markFailed`).
                $kind = $this->classifier->classifyCardException($exception);
                $this->failures->report(new SourceFailure(
                    platformId: $platformId,
                    stage: SourceStage::Detail,
                    kind: $kind,
                    detail: "Карточка {$procurement->registry_number} не получена: ".mb_substr($exception->getMessage(), 0, 500),
                    context: ['procurement_id' => $procurement->id, 'exception' => $exception::class],
                ));
                $failureReported = true;

                throw $exception;
            }

            // Проверка вёрстки ДО разбора: нет базовых селекторов — страница не обрабатывается
            // целиком (ни одна вкладка), чтобы не записать «пустые» данные с чужой вёрстки.
            // Попытка карточки не тратится: это проблема площадки, а не закупки; карточка
            // остаётся в очереди, а серия таких отказов уводит этап в ожидание.
            $missing = $this->cardPreflight->missingBaseSelectors(details: $details);

            if ($missing !== []) {
                $summary = implode('; ', array_map(
                    static fn (string $tab, array $selectors): string => $tab.': '.implode(', ', $selectors),
                    array_keys($missing),
                    $missing,
                ));
                $this->failures->report(new SourceFailure(
                    platformId: $platformId,
                    stage: SourceStage::Detail,
                    kind: SourceFailureKind::LayoutDrift,
                    detail: "Карточка {$procurement->registry_number} не обработана: нет базовых селекторов ({$summary}).",
                    context: ['procurement_id' => $procurement->id, 'missing_selectors' => $missing],
                ));

                return false;
            }

            $this->failures->reportSuccess($platformId, SourceStage::Detail);

            $commonInfo = null;
            $commonInfoSnapshotStatus = null;
            $parties = [];
            $documents = [];
            $documentsSnapshotStatus = null;
            $journalEntries = [];
            $supplierResults = null;

            if ($details->commonInfoHtml !== null) {
                $commonInfoSnapshot = $this->commonInfoParser->parse($details->commonInfoHtml);
                $parties = $this->partiesParser->parse($details->commonInfoHtml);
                $commonInfoSnapshot = $commonInfoSnapshot->withParties(
                    parties: $parties,
                    partiesComplete: $this->partiesParser->authorizesReconciliation(
                        html: $details->commonInfoHtml,
                        parties: $parties,
                    ),
                );
                $commonInfo = $commonInfoSnapshot->commonInfo;
                $commonInfoSnapshotStatus = $commonInfoSnapshot->status->value;

                $this->commonInfoProcessor->process(procurement: $procurement, snapshot: $commonInfoSnapshot);
            } else {
                Log::warning(message: 'DetailAnalysisProcessor: вкладка common-info не снята.', context: ['procurement_id' => $procurement->id]);
            }

            if ($details->documentsHtml !== null) {
                $documentsSnapshot = $this->documentsParser->parse($details->documentsHtml);
                $this->documentIndexer->index(procurement: $procurement, snapshot: $documentsSnapshot);
                $documents = $documentsSnapshot->documents;
                $documentsSnapshotStatus = $documentsSnapshot->status->value;
            } else {
                Log::warning(message: 'DetailAnalysisProcessor: вкладка documents не снята.', context: ['procurement_id' => $procurement->id]);
            }

            if ($details->eventJournalHtml !== null) {
                $journalEntries = $this->eventJournalParser->parse($details->eventJournalHtml);
                $this->eventJournalProcessor->process($procurement, $journalEntries);
            } else {
                Log::warning(message: 'DetailAnalysisProcessor: вкладка event-journal не снята.', context: ['procurement_id' => $procurement->id]);
            }

            if ($details->supplierResultsHtml !== null) {
                $supplierResults = $this->supplierResultsParser->parse($details->supplierResultsHtml);
                $this->supplierResultsProcessor->process($procurement, $supplierResults);
            }

            $detailParseOutcome = $this->detailParseOutcome(
                details: $details,
                keyFieldValues: [
                    'common-info' => [
                        'snapshot_status' => $commonInfoSnapshotStatus,
                        'ikz_year' => $commonInfo?->ikzYear,
                        'ikz_customer_code' => $commonInfo?->ikzCustomerCode,
                        'ikz_plan_number' => $commonInfo?->ikzPlanNumber,
                        'ikz_plangraph_number' => $commonInfo?->ikzPlangraphNumber,
                        'ikz_okpd_class' => $commonInfo?->ikzOkpdClass,
                        'ikz_okpd_subclass' => $commonInfo?->ikzOkpdSubclass,
                        'ikz_okpd_group' => $commonInfo?->ikzOkpdGroup,
                        'ikz_kvr' => $commonInfo?->ikzKvr,
                        'etp_name' => $commonInfo?->etpName,
                        'etp_url' => $commonInfo?->etpUrl,
                        'contact_person_name' => $commonInfo?->contactPersonName,
                        'contact_person_email' => $commonInfo?->contactPersonEmail,
                        'financing_rows' => $commonInfo?->financingRows,
                        'items' => $commonInfo?->items,
                        'parties' => $parties,
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

            if ($commonInfoSnapshotStatus !== 'complete' || $documentsSnapshotStatus !== 'complete') {
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
        } catch (PlatformBudgetExhaustedException) {
            $this->operationLogs->budgetExhausted($procurement->id);

            return false;
        } catch (Throwable $exception) {
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

    /**
     * @param  array<string, array<string, mixed>>  $keyFieldValues
     * @return array<string, array<string, mixed>>|null
     */
    private function detailParseOutcome(ProcurementDetails $details, array $keyFieldValues): ?array
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

        try {
            $baselineTabs = $this->healthBaselineReader->read()->cardTabs;
        } catch (Throwable) {
            $outcomes = [];

            foreach ($tabs as $tab => $_html) {
                $outcomes[$tab] = $this->cardAnchorProbe
                    ->probeFailed(keyFieldValues: $keyFieldValues[$tab] ?? [])
                    ->toPayload();
            }

            return $outcomes;
        }

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

    private function reportCodeError(Procurement $procurement, Throwable $exception): void
    {
        $platformId = $procurement->registry?->platform_id;

        if ($platformId === null) {
            return;
        }

        $this->failures->report(new SourceFailure(
            platformId: $platformId,
            stage: SourceStage::Detail,
            kind: SourceFailureKind::CodeError,
            detail: "Карточка {$procurement->registry_number} пропущена: ".mb_substr($exception->getMessage(), 0, 500),
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
            error: $exception->getMessage(),
            nextRetryAt: $failed ? null : now()->addMinutes($backoffMinutes * $attempts),
            failed: $failed,
            detailParseOutcome: $detailParseOutcome,
        );

        Log::warning(message: 'DetailAnalysisProcessor: снятие детального слоя упало.', context: [
            'procurement_id' => $procurement->id,
            'attempts' => $attempts,
            'error' => $exception->getMessage(),
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
