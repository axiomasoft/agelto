<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\LedgerRow;

final readonly class UnmappedStatusReporter
{
    public function __construct(private IncidentRepository $incidents) {}

    public function record(Procurement $p): void
    {
        CallerTransaction::require('Unmapped status observation');
        if ($p->status !== ProcurementStatus::Unknown) {
            return;
        }
        $raw = ContentHash::of($p->source_stage);
        $key = ContentHash::of(json_encode(['unmapped_status', $p->platform_id, $p->registry_id, $raw->hex], JSON_THROW_ON_ERROR));
        // This is a new-raw-status incident, not an execution circuit. Resolved history is retained.
        $prior = LedgerRow::one("SELECT id,state FROM prc_process_incidents WHERE fingerprint=decode(?,'hex') ORDER BY id FOR UPDATE", [$key->hex]);
        if ($prior?->string('state') === 'resolved') {
            return;
        }
        $this->incidents->open($key, 'acquisition', 'unmapped_status',
            ['platform_id' => $p->platform_id, 'registry_id' => $p->registry_id, 'source_stage_hash' => $raw->hex],
            ListingStoreRepository::id('unmapped:'.$key->hex), ListingStoreRepository::id('unmapped-event:'.$key->hex), ListingStoreRepository::id('unmapped-project:'.$key->hex));
    }
}
