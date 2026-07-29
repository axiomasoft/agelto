<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Casts\PostgresTextArrayCast;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Scopes\PublicPlatformScope;
use Database\Factories\Procurement\ProcurementFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Текущее состояние закупки (compiledRelease-проекция поверх редакций).
 *
 * @property-read Registry|null $registry
 */
#[UseFactory(ProcurementFactory::class)]
final class Procurement extends Model
{
    use HasFactory;

    protected $table = 'prc_procurements';

    protected $fillable = [
        'uuid',
        'registry_id',
        'registry_number',
        'etp_number',
        'ikz',
        'title',
        'status',
        'source_stage',
        'procedure_type',
        'procedure_type_raw',
        'customer_org_id',
        'initial_price',
        'currency',
        'advance_percent',
        'okpd2_code',
        'okpd2_codes',
        'okved_code',
        'okved_additional',
        'region_code',
        'published_at',
        'source_updated_at',
        'bidding_starts_at',
        'bidding_ends_at',
        'summary_at',
        'detail_url',
        'detail_fetched_at',
        'detail_attempts',
        'detail_last_error',
        'detail_next_retry_at',
        'detail_failed',
        'registry_guid',
        'current_revision_id',
        'current_revision_detected_at',
        'revision_count',
        'ignored_at',
        'ignore_reason',
        'attributes',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProcurementStatus::class,
            'okpd2_codes' => PostgresTextArrayCast::class,
            'attributes' => AsCollection::class,
            'initial_price' => 'decimal:2',
            'advance_percent' => 'decimal:2',
            'detail_failed' => 'boolean',
            'detail_attempts' => 'integer',
            'revision_count' => 'integer',
            'published_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'bidding_starts_at' => 'datetime',
            'bidding_ends_at' => 'datetime',
            'summary_at' => 'datetime',
            'detail_fetched_at' => 'datetime',
            'detail_next_retry_at' => 'datetime',
            'current_revision_detected_at' => 'datetime',
            'ignored_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $procurement): void {
            $procurement->uuid ??= (string) Str::uuid7();
        });
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }

    /**
     * Единственный носитель правила показа (§4 root/document-contour.md, D91) — вырезает закупки
     * служебных площадок (`is_public=false`) из публичной витрины. `Procurement` не несёт
     * `platform_id` напрямую (площадка достижима только цепочкой `registry.platform_id`, §1.2
     * контракта) — путь до `Platform` вложенный, в отличие от `Document`/`Estimate`.
     *
     * @param  Builder<Procurement>  $query
     * @return Builder<Procurement>
     */
    public function scopePublicPlatform(Builder $query): Builder
    {
        return PublicPlatformScope::apply($query, 'registry.platform');
    }
}
