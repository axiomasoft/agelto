<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Scopes\PublicPlatformScope;
use Database\Factories\Procurement\ProcurementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Текущее состояние закупки (compiledRelease-проекция поверх редакций).
 *
 * @property ProcurementStatus $status
 * @property string $uuid
 * @property int $registry_id
 * @property int $platform_id
 * @property string $source_identity_type
 * @property string $source_identity_value
 * @property string $title
 * @property string $source_stage
 * @property Carbon $published_at
 * @property string|null $initial_price
 * @property bool $source_updated_has_day_precision
 * @property Carbon|null $source_updated_before
 * @property string|null $currency
 * @property string|null $procedure_type
 * @property string|null $advance_percent
 * @property string|null $okved_code
 * @property string|null $okved_additional
 * @property string|null $region_code
 * @property string|null $detail_url
 * @property Carbon|null $bidding_starts_at
 * @property Carbon|null $bidding_ends_at
 * @property Carbon|null $summary_at
 * @property int|null $customer_org_id
 * @property Carbon|null $detail_fetched_at
 * @property Carbon|null $source_updated_at
 * @property int $revision_count
 * @property-read Registry|null $registry
 * @property-read string|null $procedure_type_raw
 * @property-read int $head_generation Subject head, advanced by canonical publication under the subject lock.
 * @property-read int $detail_cycle_seq
 * @property-read int $status_cycle_seq
 * @property-read Carbon|null $next_status_due_at
 */
#[UseFactory(ProcurementFactory::class)]
#[Fillable([
    'uuid',
    'registry_id',
    'platform_id',
    'source_identity_type',
    'source_identity_value',
    'title',
    'status',
    'succeeded_at',
    'source_stage',
    'procedure_type',
    'procedure_type_raw',
    'customer_org_id',
    'initial_price',
    'currency',
    'advance_percent',
    'okved_code',
    'okved_additional',
    'region_code',
    'published_at',
    'source_updated_at', 'source_updated_has_day_precision', 'source_updated_before', 'source_observed_at', 'source_order_ambiguous',
    'bidding_starts_at',
    'bidding_ends_at',
    'summary_at',
    'detail_url',
    'current_revision_id',
    'current_revision_detected_at',
    'revision_count',
    'ignored_at',
    'ignore_reason',
    'attributes',
    'first_seen_at',
    'last_seen_at',
])]
#[Table(name: 'prc_procurements')]
final class Procurement extends Model
{
    /** @use HasFactory<ProcurementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProcurementStatus::class,
            'succeeded_at' => 'datetime',
            'attributes' => AsCollection::class,
            'initial_price' => 'decimal:2',
            'advance_percent' => 'decimal:2',
            'revision_count' => 'integer',
            'published_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'source_updated_before' => 'datetime',
            'source_observed_at' => 'datetime',
            'source_updated_has_day_precision' => 'boolean',
            'source_order_ambiguous' => 'boolean',
            'bidding_starts_at' => 'datetime',
            'bidding_ends_at' => 'datetime',
            'summary_at' => 'datetime',
            'next_status_due_at' => 'datetime',
            'current_revision_detected_at' => 'datetime',
            'ignored_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $procurement): void {
            if ($procurement->isDirty(['platform_id', 'registry_id', 'source_identity_type', 'source_identity_value'])) {
                throw new \LogicException('Procurement primary identity is immutable.');
            }
        });
        self::creating(function (self $procurement): void {
            $procurement->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<Registry, $this> */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }

    /** @return HasMany<ProcurementRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(related: ProcurementRevision::class)
            ->orderBy('detected_at')
            ->orderBy('revision_number');
    }

    /** @return HasMany<ProcurementEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(related: ProcurementEvent::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /** @return HasMany<Award, $this> */
    public function awards(): HasMany
    {
        return $this->hasMany(related: Award::class)
            ->orderBy('awarded_at')
            ->orderBy('id');
    }

    /** @return HasMany<Protocol, $this> */
    public function protocols(): HasMany
    {
        return $this->hasMany(related: Protocol::class)
            ->orderBy('published_at')
            ->orderBy('id');
    }

    /**
     * Единственный носитель правила показа (§4 root/document-contour.md, D91) — вырезает закупки
     * служебных площадок (`is_public=false`) из публичной витрины. Registry/platform composite
     * FK гарантирует совпадение canonical platform_id с путём registry.platform.
     *
     * @param  Builder<Procurement>  $query
     * @return Builder<Procurement>
     */
    public function scopePublicPlatform(Builder $query): Builder
    {
        return PublicPlatformScope::apply($query, 'registry.platform');
    }
}
