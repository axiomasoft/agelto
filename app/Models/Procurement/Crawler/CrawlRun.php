<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Process\ProcessRun;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable subject snapshot listing-обхода одного ProcessRun. Execution
 * state/lease/attempt counters здесь нет: исполнение принадлежит только prc_process_work.
 * Legacy prc_crawler_runs (Run) хранит историю прежнего owner.
 *
 * @property string $id
 * @property string $process_run_id
 * @property AcquisitionKind $listing_kind
 * @property array<string, mixed> $filter_payload
 * @property int $filter_schema_version
 * @property mixed $filter_hash
 * @property mixed $access_policy_hash
 * @property string $adapter_version
 * @property-read ProcessRun $processRun
 */
#[Table(name: 'prc_crawl_runs', keyType: 'string', incrementing: false, timestamps: false)]
final class CrawlRun extends Model
{
    /** @return BelongsTo<ProcessRun, $this> */
    public function processRun(): BelongsTo
    {
        return $this->belongsTo(ProcessRun::class, 'process_run_id');
    }

    /** @return HasMany<CrawlBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(CrawlBlock::class, 'crawl_run_id');
    }

    protected function casts(): array
    {
        return [
            'listing_kind' => AcquisitionKind::class,
            'filter_payload' => 'array',
        ];
    }
}
