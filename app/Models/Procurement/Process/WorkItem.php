<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Models\Procurement\Crawler\CrawlBlock;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Одна логическая работа над immutable input revision одной стадии (execution#s01/#s04).
 * Ровно один concrete subject FK своей стадии (owner CHECK); durable независимо от Redis.
 * Read model: guarded mutation только через Process repositories с fencing.
 *
 * @property string $id
 * @property StageCode $stage
 * @property mixed $work_key
 * @property array<string, mixed> $work_identity
 * @property int $work_identity_schema_version
 * @property string|null $intent_id
 * @property int $input_revision
 * @property mixed $input_hash
 * @property array<string, mixed> $input_payload
 * @property int $input_schema_version
 * @property WorkState $state
 * @property string|null $reason_code
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $provider_recovery_due_at
 * @property int $priority
 * @property int|null $platform_id
 * @property string $cohort_key
 * @property string|null $origin_run_id
 * @property string|null $crawl_block_id
 * @property int|null $procurement_id
 * @property int|null $document_id
 * @property int|null $document_version_id
 * @property string|null $analysis_window_id
 * @property string|null $analysis_run_id
 * @property int $generation
 * @property string|null $owner_token
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $heartbeat_at
 * @property int $dispatch_generation
 * @property CarbonImmutable|null $dispatch_claim_until
 * @property array<string, mixed>|null $checkpoint
 * @property int|null $checkpoint_schema_version
 * @property int $checkpoint_seq
 * @property int $execution_attempts
 * @property int $transport_attempts
 * @property int $semantic_reasks
 * @property int $item_failures
 * @property int $infrastructure_interruptions
 * @property int $transition_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CrawlBlock|null $crawlBlock
 * @property-read Procurement|null $procurement
 * @property-read Document|null $document
 * @property-read OperationIntent|null $intent
 */
#[Table(name: 'prc_process_work', keyType: 'string', incrementing: false, timestamps: false)]
final class WorkItem extends Model
{
    /** @return BelongsTo<CrawlBlock, $this> */
    public function crawlBlock(): BelongsTo
    {
        return $this->belongsTo(CrawlBlock::class, 'crawl_block_id');
    }

    /** @return BelongsTo<Procurement, $this> */
    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class, 'procurement_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return BelongsTo<OperationIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(OperationIntent::class, 'intent_id');
    }

    /** @return HasMany<WorkAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(WorkAttempt::class, 'work_id');
    }

    /** @return HasMany<Transition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(Transition::class, 'work_id');
    }

    protected function casts(): array
    {
        return [
            'stage' => StageCode::class,
            'state' => WorkState::class,
            'work_identity' => 'array',
            'input_payload' => 'array',
            'checkpoint' => 'array',
            'due_at' => 'immutable_datetime',
            'provider_recovery_due_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'dispatch_claim_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
