<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Detail\CommonInfoProcessor;
use App\Services\Procurement\Detail\EventJournalProcessor;
use App\Services\Procurement\Detail\OrganizationPartiesProcessor;
use App\Services\Procurement\Detail\SupplierResultsProcessor;
use App\Services\Procurement\DocumentDownloader;
use App\Services\Procurement\DocumentIndexer;
use App\Services\Procurement\Eis\Card\CommonInfoParser;
use App\Services\Procurement\Eis\Card\DocumentsParser;
use App\Services\Procurement\Eis\Card\EventJournalParser;
use App\Services\Procurement\Eis\Card\PartiesParser;
use App\Services\Procurement\Eis\Card\SupplierResultsParser;
use App\Services\Procurement\SourceMethodFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Оркестратор детального анализа закупки (`02-crawling.md` §5): забор карточки (P3.1) →
 * процессоры вкладок в фиксированном порядке common-info → organizations → documents(+download)
 * → event-journal → supplier-results → `detail_fetched_at`. Ошибка на любом шаге для ОДНОЙ
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
        private OrganizationPartiesProcessor $organizationPartiesProcessor,
        private DocumentsParser $documentsParser,
        private DocumentIndexer $documentIndexer,
        private DocumentDownloader $documentDownloader,
        private EventJournalParser $eventJournalParser,
        private EventJournalProcessor $eventJournalProcessor,
        private SupplierResultsParser $supplierResultsParser,
        private SupplierResultsProcessor $supplierResultsProcessor,
        private ProcurementStoreRepository $procurementStore,
    ) {}

    public function run(Procurement $procurement): void
    {
        try {
            $registry = $procurement->registry;

            if ($registry === null) {
                throw new RuntimeException("Procurement #{$procurement->id}: registry_id не резолвится в существующий Registry.");
            }

            $sourceMethod = $this->sourceMethodFactory->make(
                method: SourceMethodEnum::from((string) config('crawler.detail_source_method', SourceMethodEnum::Csv->value)),
            );

            $details = $sourceMethod->collectDetails(sourceNumber: $procurement->source_number, registry: $registry);

            if ($details->commonInfoHtml !== null) {
                $this->commonInfoProcessor->process($procurement, $this->commonInfoParser->parse($details->commonInfoHtml));
                $this->organizationPartiesProcessor->process($procurement, $this->partiesParser->parse($details->commonInfoHtml));
            } else {
                Log::warning(message: 'DetailAnalysisProcessor: вкладка common-info не снята.', context: ['procurement_id' => $procurement->id]);
            }

            if ($details->documentsHtml !== null) {
                $this->documentIndexer->index($procurement, $this->documentsParser->parse($details->documentsHtml));
                $this->downloadPendingDocuments($procurement);
            } else {
                Log::warning(message: 'DetailAnalysisProcessor: вкладка documents не снята.', context: ['procurement_id' => $procurement->id]);
            }

            if ($details->eventJournalHtml !== null) {
                $this->eventJournalProcessor->process($procurement, $this->eventJournalParser->parse($details->eventJournalHtml));
            } else {
                Log::warning(message: 'DetailAnalysisProcessor: вкладка event-journal не снята.', context: ['procurement_id' => $procurement->id]);
            }

            if ($details->supplierResultsHtml !== null) {
                $this->supplierResultsProcessor->process($procurement, $this->supplierResultsParser->parse($details->supplierResultsHtml));
            }

            $this->procurementStore->markDetailFetched(procurement: $procurement, fetchedAt: now());
        } catch (Throwable $exception) {
            $this->markFailed(procurement: $procurement, exception: $exception);
        }
    }

    private function downloadPendingDocuments(Procurement $procurement): void
    {
        Document::query()
            ->where('procurement_id', $procurement->id)
            ->where('status', DocumentStatus::Discovered)
            ->whereNull('removed_at')
            ->pendingRetry()
            ->each(function (Document $document): void {
                $this->documentDownloader->download($document);
            });
    }

    private function markFailed(Procurement $procurement, Throwable $exception): void
    {
        $attempts = $procurement->detail_attempts + 1;
        $maxAttempts = (int) config('crawler.detail_max_attempts', 5);
        $backoffMinutes = (int) config('crawler.detail_retry_backoff_minutes', 15);
        $failed = $attempts >= $maxAttempts;

        $this->procurementStore->markDetailFailed(
            procurement: $procurement,
            error: $exception->getMessage(),
            nextRetryAt: $failed ? null : now()->addMinutes($backoffMinutes * $attempts),
            failed: $failed,
        );

        Log::warning(message: 'DetailAnalysisProcessor: снятие детального слоя упало.', context: [
            'procurement_id' => $procurement->id,
            'attempts' => $attempts,
            'error' => $exception->getMessage(),
        ]);
    }
}
