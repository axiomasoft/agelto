<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\LedgerRow;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Read projection only: never creates control, health, circuit or retry state. */
final readonly class NetworkEligibilityRepository
{
    /** @return array<int, string> */
    public function platforms(): array
    {
        $platforms = [];
        foreach (LedgerRow::all('SELECT id, code FROM prc_platforms ORDER BY id') as $row) {
            $platforms[$row->int('id')] = $row->string('code');
        }

        return $platforms;
    }

    public function hasAvailableTarget(int $platformId, StageCode $stage): bool
    {
        // Unknown/due-open targets remain candidates for a bounded canary. This read is not a permit.
        $health = PackageSchema::tables()['target_health'];

        return LedgerRow::one("SELECT t.id FROM prc_source_targets t
            JOIN prc_proxyma_target_links l ON l.source_target_id=t.id AND l.tenant_key='system'
            JOIN {$health} h ON h.target_id=l.target_id AND h.tenant_key=l.tenant_key
            WHERE t.platform_id = ? AND t.capability = ? AND t.retired_at IS NULL
              AND NOT (h.state = 'open' AND h.open_until > clock_timestamp())
              AND (t.endpoint_policy_schema_version = 1 OR EXISTS (
                SELECT 1 FROM prc_adapter_health a JOIN prc_platforms p ON p.id = a.platform_id
                WHERE a.platform_id = t.platform_id AND a.capability = t.capability AND a.state <> 'held'
                    AND t.endpoint_policy->'adapter_contract' = jsonb_build_object('platform_code', p.code,
                        'capability', a.capability, 'adapter_version', a.adapter_version, 'contract_version', a.contract_version)))
            ORDER BY t.id LIMIT 1", [$platformId, $stage->value]) !== null;
    }
}
