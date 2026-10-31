<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Enums\Procurement\Network\AdapterVerdict;
use Illuminate\Support\Facades\DB;

/** Persist authoritative input facts for passive health-reader tests, without a network call. */
final class AdapterObservationFixtures
{
    public static function detail(string $platformCode, AdapterVerdict $verdict, bool $stale = false, ?\DateTimeInterface $occurredAt = null): void
    {
        $id = LedgerFixtures::uuid();
        $contract = new AdapterContractRef($platformCode, 'detail', 'fixture-detail/1', 'fixture-contract/1');
        $details = ['schema_version' => 1, 'verdict' => $verdict->value, 'contract' => $contract->toArray()];
        DB::insert("INSERT INTO prc_observations (observation_id,kind,reason_code,input_hash,generation,
            epochs,epochs_schema_version,stale,details,details_schema_version,occurred_at)
            VALUES (?,'adapter_validation','fixture',decode(?,'hex'),1,'{}',1,?,?::jsonb,1,?)",
            [$id->value, LedgerFixtures::hash($id->value)->hex, $stale, json_encode($details, JSON_THROW_ON_ERROR),
                ($occurredAt ?? new \DateTimeImmutable)->format('Y-m-d H:i:s.uP')]);
    }
}
