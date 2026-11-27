<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\Core\RunKind;
use App\Models\Procurement\Crawler\CrawlRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Намерение запуска: immutable settings snapshot и конечный discovery
 * collecting → complete|incomplete. Не вечный parent downstream; writer — RunStoreRepository.
 *
 * @property string $id
 * @property RunKind $kind
 * @property string|null $trigger_key
 * @property int|null $profile_id
 * @property array<string, mixed> $configuration_snapshot
 * @property int $configuration_schema_version
 * @property mixed $configuration_hash
 * @property int|null $requested_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $discovery_closed_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property string $discovery_outcome
 * @property string|null $discovery_reason_code
 * @property int $transition_seq
 * @property int $projection_version
 * @property-read CrawlRun|null $crawlRun
 */
#[Table(name: 'prc_process_runs', keyType: 'string', incrementing: false, timestamps: false)]
final class ProcessRun extends Model
{
    /** @return HasMany<RunWork, $this> */
    public function workLinks(): HasMany
    {
        return $this->hasMany(RunWork::class, 'run_id');
    }

    /** @return HasOne<CrawlRun, $this> */
    public function crawlRun(): HasOne
    {
        return $this->hasOne(CrawlRun::class, 'process_run_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => RunKind::class,
            'configuration_snapshot' => 'array',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'discovery_closed_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
        ];
    }
}
