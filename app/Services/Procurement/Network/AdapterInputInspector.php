<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Details\ParsedProcurementDetails;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Exceptions\Procurement\Network\AdapterInputRejected;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Health\CardLayoutPreflight;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\PlatformAdapterRegistry;
use App\Services\Procurement\Process\DatabaseFailureClassifier;

/** Pure full-card inspection. It cannot admit transport, mutate health or persist domain data. */
final readonly class AdapterInputInspector
{
    public function __construct(private HealthBaselineReader $baselines, private CardLayoutPreflight $preflight,
        private PlatformAdapterRegistry $adapters) {}

    public function prepare(Registry $registry): HealthBaseline
    {
        return $this->baselines->read($registry);
    }

    /** @return AdapterInspection<ProcurementDetails|\Throwable> */
    public function details(ProcurementDetails $details, Registry $registry,
        OutboundExecutionContext $context, HealthBaseline $baseline): AdapterInspection
    {
        $missing = $this->preflight->missingBaseSelectors($details, $baseline);
        $identityMissing = false;
        foreach ([$details->commonInfoHtml, $details->documentsHtml, $details->eventJournalHtml, $details->supplierResultsHtml] as $html) {
            if ($html !== null && ! str_contains($html, $details->registryNumber)) {
                $identityMissing = true;
            }
        }
        $code = $identityMissing ? 'detail.page_mismatch' : ($missing === [] ? null : 'detail.layout_drift');
        $parsed = null;
        $partial = false;
        if ($code === null) {
            $parser = $this->adapters->detailParser($registry);
            try {
                $context->tick();
                $common = $details->commonInfoHtml === null ? null : $parser->commonInfo($details->commonInfoHtml);
                $context->tick();
                $documents = $details->documentsHtml === null ? null : $parser->documents($details->documentsHtml);
                $context->tick();
                $journal = $details->eventJournalHtml === null ? null : $parser->eventJournal($details->eventJournalHtml);
                $context->tick();
                $supplier = $details->supplierResultsHtml === null ? null : $parser->supplierResults($details->supplierResultsHtml);
                $parsed = new ParsedProcurementDetails($common, $documents, $journal, $supplier);
                if ($common === null || ! $common->authorizesCommonInfoReconciliation() || ! $common->authorizesPartiesReconciliation()
                    || $documents === null || ! $documents->authorizesReconciliation()) {
                    $partial = true;
                }
            } catch (\Throwable $failure) {
                if (DatabaseFailureClassifier::isTransient($failure)
                    || $failure instanceof DependencyUnavailable
                    || $failure instanceof OutboundOwnershipLost) {
                    throw $failure;
                }

                return new AdapterInspection($failure,
                    AdapterVerdict::HandlerFault, 'detail.parser_exception');
            }
        }
        if ($code !== null) {
            return new AdapterInspection(new AdapterInputRejected($code, $missing),
                AdapterVerdict::ContractFailure, $code,
                new ContentHash(hash('sha256', $code.':'.json_encode($missing, JSON_THROW_ON_ERROR))));
        }

        return new AdapterInspection(new ProcurementDetails($details->registryNumber,
            $details->procedureUrlType, $details->commonInfoHtml, $details->documentsHtml, $details->eventJournalHtml,
            $details->supplierResultsHtml, $parsed), $partial ? AdapterVerdict::Partial : AdapterVerdict::Accepted,
            $partial ? 'detail.incomplete_snapshot' : 'detail.full_contract');
    }
}
