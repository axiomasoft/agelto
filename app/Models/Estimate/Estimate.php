<?php

declare(strict_types=1);

namespace App\Models\Estimate;

use App\Models\Ai\Analysis;
use App\Models\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use Database\Factories\Estimate\EstimateFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Распарсенная смета (нормализованное хранилище результата AI-извлечения).
 *
 * @use HasFactory<EstimateFactory>
 */
final class Estimate extends Model
{
    /** @use HasFactory<EstimateFactory> */
    use HasFactory;

    protected $table = 'est_estimates';

    protected $fillable = [
        'uuid',
        'analysis_id',
        'document_version_id',
        'procurement_id',
        'title',
        'estimate_number',
        'total_amount',
        'currency',
        'base_year',
        'price_level',
        'passport',
        'raw_result',
        'raw_result_ref',
        'rows_expected',
        'rows_extracted',
        'validation_status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'base_year' => 'integer',
            'passport' => AsCollection::class,
            'raw_result' => AsCollection::class,
            'rows_expected' => 'integer',
            'rows_extracted' => 'integer',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(related: Analysis::class);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class);
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(related: Procurement::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(related: Section::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(related: Item::class);
    }

    public function totals(): HasMany
    {
        return $this->hasMany(related: Total::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $estimate): void {
            $estimate->uuid ??= (string) Str::uuid7();
        });
    }
}
