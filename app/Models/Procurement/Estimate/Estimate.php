<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use App\Enums\Procurement\Estimate\CalcMethod;
use App\Enums\Procurement\Estimate\EstimateSourceKind;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\Scopes\PublicPlatformScope;
use App\Models\User;
use Database\Factories\Procurement\Estimate\EstimateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Распарсенная смета (нормализованное хранилище результата AI-извлечения).
 *
 * @property string $uuid
 * @property int $platform_id
 * @property int $ordinal
 * @property string|null $total_amount
 * @property string|null $estimate_number
 * @property string|null $currency
 * @property int|null $base_year
 * @property string|null $price_level
 * @property string|null $regulatory_base
 * @property CalcMethod|null $calc_method
 * @property string|null $labor_hours_workers
 * @property string|null $labor_hours_machinists
 * @property string|null $document_number
 * @property Carbon|null $document_date
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property int|null $rows_extracted
 * @property string $validation_status
 * @property Collection<int, string>|null $validation_notes ValidationReport::$notes сметы целиком; null — валидатор ничего не нашёл
 * @property string|null $raw_result_ref
 * @property string|null $raw_result_disk
 * @property Collection<array-key, mixed>|null $passport
 * @property string|null $source_sheet_name
 * @property int|null $source_page_start
 * @property-read DocumentVersion|null $documentVersion
 * @property-read Analysis|null $analysis
 * @property-read int $items_count `withCount('items')` — счётчик витрины (EstimateResource::getEloquentQuery())
 * @property-read int $marked_items_count `withCount('items as marked_items_count',...)` — то же, отфильтровано по `quality IS NOT NULL`
 */
#[UseFactory(EstimateFactory::class)]
#[Fillable([
    'uuid',
    'analysis_id',
    'document_version_id',
    'procurement_id',
    'platform_id',
    'ordinal',
    'estimate_number',
    'total_amount',
    'currency',
    'base_year',
    'price_level',
    'regulatory_base',
    'calc_method',
    'labor_hours_workers',
    'labor_hours_machinists',
    'document_number',
    'document_date',
    'period_start',
    'period_end',
    'passport',
    'raw_result',
    'raw_result_ref',
    'raw_result_disk',
    'rows_expected',
    'rows_extracted',
    'validation_status',
    'validation_notes',
    'verified_at',
    'verified_by',
    'source_sheet_name',
    'source_row_start',
    'source_row_end',
    'source_page_start',
    'source_page_end',
])]
#[Table(name: 'prc_estimates')]
final class Estimate extends Model
{
    /** @use HasFactory<EstimateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'total_amount' => 'decimal:2',
            'base_year' => 'integer',
            'calc_method' => CalcMethod::class,
            'labor_hours_workers' => 'decimal:6',
            'labor_hours_machinists' => 'decimal:6',
            'document_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'passport' => AsCollection::class,
            'raw_result' => AsCollection::class,
            // Примитивный `'collection'`, НЕ `AsCollection::class` — тот же null-safety довод, что
            // `Item::$quality`: класс-каст пишет JSON `"null"`
            // вместо SQL NULL на пустом значении.
            'validation_notes' => 'collection',
            'rows_expected' => 'integer',
            'rows_extracted' => 'integer',
            'verified_at' => 'datetime',
            'source_row_start' => 'integer',
            'source_row_end' => 'integer',
            'source_page_start' => 'integer',
            'source_page_end' => 'integer',
        ];
    }

    /**
     * Подпись сметы для витрины — вычислима из `estimate_number`/`ordinal`, колонки не имеет
     * (хранимое производное значение расходилось с источником при правке номера оператором).
     */
    /** @return Attribute<string, never> */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->estimate_number !== null && $this->estimate_number !== ''
                ? "Смета №{$this->estimate_number}"
                : "Смета №{$this->ordinal}",
        );
    }

    /**
     * Система координат адреса сметы в многосметном документе — производная, колонки не имеет
     * лист книги → `Sheet`, страничный сегмент → `Pages`, ни то ни другое → `WholeDocument`.
     */
    /** @return Attribute<EstimateSourceKind, never> */
    protected function sourceKind(): Attribute
    {
        return Attribute::make(
            get: fn (): EstimateSourceKind => match (true) {
                $this->source_sheet_name !== null => EstimateSourceKind::Sheet,
                $this->source_page_start !== null => EstimateSourceKind::Pages,
                default => EstimateSourceKind::WholeDocument,
            },
        );
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(related: Analysis::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class);
    }

    /** @return BelongsTo<Procurement, $this> */
    public function procurement(): BelongsTo
    {
        return $this->belongsTo(related: Procurement::class);
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'verified_by');
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(related: Section::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(related: Item::class);
    }

    /** @return HasMany<Total, $this> */
    public function totals(): HasMany
    {
        return $this->hasMany(related: Total::class);
    }

    /** @return HasMany<WorkType, $this> */
    public function workTypes(): HasMany
    {
        return $this->hasMany(related: WorkType::class);
    }

    /**
     * Исключает сметы служебных площадок (`is_public=false`) из публичной витрины.
     *
     * @param  Builder<Estimate>  $query
     * @return Builder<Estimate>
     */
    public function scopePublicPlatform(Builder $query): Builder
    {
        return PublicPlatformScope::apply($query, 'platform');
    }

    protected static function booted(): void
    {
        self::creating(function (self $estimate): void {
            $estimate->uuid ??= (string) Str::uuid7();
        });
    }
}
