<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\LanePolicy;
use App\Models\Procurement\Process\Lane;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer capacity lane rows `prc_process_lanes` по versioned policy (execution#lane-defaults).
 * Существующие lanes запроса FOR UPDATE в capacity-tier порядке id, затем INSERT недостающих
 * parent перед child. Sync меняет только W/C/low/refill и policy_version; parent topology
 * immutable, fairness cursor и reservations не сбрасываются. Admission counts — следующие пункты.
 */
final readonly class LaneRepository
{
    /** @param  list<LanePolicy>  $policies */
    public function syncLanes(array $policies, int $policyVersion): void
    {
        CallerTransaction::require('Lane policy sync');
        if ($policies === [] || $policyVersion < 1) {
            throw new \InvalidArgumentException('Lane sync requires policies and a positive policy version.');
        }
        usort($policies, static fn (LanePolicy $a, LanePolicy $b): int => [$a->parentKey !== null, $a->key] <=> [$b->parentKey !== null, $b->key]);
        $keys = array_map(static fn (LanePolicy $policy): string => $policy->key, $policies);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new \InvalidArgumentException('Lane keys must be unique.');
        }

        /** @var array<string, Lane> $rows */
        $rows = [];
        foreach (Lane::query()->whereIn('lane_key', $keys)->orderBy('id')->lockForUpdate()->get() as $row) {
            $rows[$row->lane_key] = $row;
        }

        foreach ($policies as $policy) {
            $parentId = $policy->parentKey === null
                ? null
                : ($rows[$policy->parentKey]->id ?? throw new \InvalidArgumentException('Lane parent must be synced with its child: '.$policy->key));
            $limits = [$policy->maxOutstanding, $policy->maxRunning, $policy->lowWatermark, $policy->refillMax, $policyVersion];
            if (! isset($rows[$policy->key])) {
                DB::insert(
                    'INSERT INTO prc_process_lanes
                       (id, lane_key, parent_lane_id, max_outstanding, max_running, low_watermark, refill_max, policy_version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (lane_key) DO NOTHING',
                    [Str::lower((string) Str::uuid()), $policy->key, $parentId, ...$limits],
                );
                $rows[$policy->key] = Lane::query()->where('lane_key', $policy->key)->lockForUpdate()->sole();
            }
            $row = $rows[$policy->key];
            if ($row->parent_lane_id !== $parentId) {
                throw new \LogicException('Lane topology is immutable: '.$policy->key);
            }
            if ([$row->max_outstanding, $row->max_running, $row->low_watermark, $row->refill_max, $row->policy_version] !== $limits) {
                DB::update(
                    'UPDATE prc_process_lanes SET max_outstanding = ?, max_running = ?, low_watermark = ?, refill_max = ?, policy_version = ? WHERE id = ?',
                    [...$limits, $row->id],
                );
            }
        }
    }
}
