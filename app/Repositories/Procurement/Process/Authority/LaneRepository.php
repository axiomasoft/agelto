<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Authority\LaneLoad;
use App\Dto\Procurement\Process\Authority\LanePolicy;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Models\Procurement\Process\Lane;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer capacity lane rows `prc_process_lanes` по versioned policy.
 * Существующие lanes запроса FOR UPDATE в capacity-tier порядке id, затем INSERT недостающих
 * parent перед child. Sync меняет только W/C/low/refill и policy_version; parent topology
 * immutable, fairness cursor и reservations не сбрасываются. Admission counts читаются из reservations.
 */
final readonly class LaneRepository
{
    public function __construct(private ProcessPayloadCodec $codec) {}

    /**
     * Unlocked load lanes для выбора feeder; отсутствующая lane не возвращается.
     *
     * @param  list<string>  $keys
     * @return array<string, LaneLoad>
     */
    public function loads(array $keys): array
    {
        $loads = [];
        foreach (LedgerRow::all(
            'SELECT l.id, l.lane_key, l.max_outstanding, l.low_watermark, l.refill_max, l.fairness_cursor::text AS fairness_cursor,
                    l.fairness_cursor_schema_version, (SELECT count(*) FROM prc_process_reservations r WHERE r.lane_id = l.id) AS outstanding
             FROM prc_process_lanes l WHERE l.lane_key IN ('.LedgerRow::placeholders(count($keys)).')',
            $keys,
        ) as $row) {
            $loads[$row->string('lane_key')] = new LaneLoad(
                new ProcessUuid($row->string('id')),
                $row->string('lane_key'),
                $row->int('max_outstanding'),
                $row->int('low_watermark'),
                $row->int('refill_max'),
                $row->int('outstanding'),
                $this->codec->decodeFairnessCursor(new VersionedPayload($row->string('fairness_cursor'), $row->int('fairness_cursor_schema_version'))),
            );
        }

        return $loads;
    }

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
