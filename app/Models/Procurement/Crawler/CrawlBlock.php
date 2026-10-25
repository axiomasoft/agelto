<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Реальный subject listing work: диапазон/partition, typed cursor и terminal proof. head_generation
 * меняется только при supersede/split input; cursor/proof пишутся лишь guarded checkpoint/finalize.
 *
 * @property string $id
 * @property string $crawl_run_id
 * @property string|null $parent_block_id
 * @property string $block_key
 * @property array<string, mixed> $partition_payload
 * @property int $partition_schema_version
 * @property mixed $filter_hash
 * @property int $head_generation
 * @property int $input_revision
 * @property array<string, mixed> $cursor
 * @property int $cursor_schema_version
 * @property array<string, mixed>|null $terminal_proof
 * @property int|null $terminal_proof_schema_version
 * @property string|null $limitation_reason_code
 * @property-read CrawlRun $crawlRun
 */
#[Table(name: 'prc_crawl_blocks', keyType: 'string', incrementing: false, timestamps: false)]
final class CrawlBlock extends Model
{
    /** @return BelongsTo<CrawlRun, $this> */
    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'crawl_run_id');
    }

    protected function casts(): array
    {
        return [
            'partition_payload' => 'array',
            'cursor' => 'array',
            'terminal_proof' => 'array',
        ];
    }
}
