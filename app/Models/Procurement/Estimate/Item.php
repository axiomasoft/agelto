<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use Database\Factories\Procurement\Estimate\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Позиция сметы (строка).
 *
 * @property int|null $section_id
 * @property string|null $code
 * @property string $name
 * @property string|null $unit
 * @property string|null $unit_base
 * @property string|null $unit_multiplier
 * @property string|null $quantity
 * @property string|null $unit_price_base
 * @property string|null $unit_price_curr
 * @property string|null $total_price_base
 * @property string|null $total_price_curr
 * @property string|null $index_code
 * @property string|null $index_value
 * @property string|null $source_position_number
 * @property int|null $work_type_id
 * @property Collection<int, mixed>|null $quality провенанс-ключ верхнего уровня model/validator (research/12-uncertainty-model.md §3.2); null — чистая позиция
 */
#[UseFactory(ItemFactory::class)]
final class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $table = 'prc_estimate_items';

    protected $fillable = [
        'estimate_id',
        'section_id',
        'row_index',
        'code',
        'name',
        'unit',
        'unit_base',
        'unit_multiplier',
        'quantity',
        'unit_price_base',
        'unit_price_curr',
        'total_price_base',
        'total_price_curr',
        'index_code',
        'index_value',
        'source_position_number',
        'work_type_id',
        'attributes',
        'quality',
    ];

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'unit_multiplier' => 'decimal:6',
            'quantity' => 'decimal:6',
            'unit_price_base' => 'decimal:2',
            'unit_price_curr' => 'decimal:2',
            'total_price_base' => 'decimal:2',
            'total_price_curr' => 'decimal:2',
            'index_value' => 'decimal:6',
            'attributes' => AsCollection::class,
            // Примитивный `'collection'`, НЕ `AsCollection::class`: класс-каст (`Castable`)
            // безусловно JSON-кодирует значение на записи (`Json::encode(null)` → строка `"null"`,
            // НЕ SQL NULL) — ломает инвариант «колонка NULL — ни один писатель ничего не сказал»
            // (research/12-uncertainty-model.md §3.2) и партиционирующий индекс `WHERE quality IS
            // NOT NULL` (P8.5). Примитивный тип пропускает кодирование при `null` до JSON (Laravel
            // `HasAttributes::setAttribute()`), сохраняя SQL NULL.
            'quality' => 'collection',
        ];
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(related: Estimate::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(related: Section::class);
    }

    /** @return BelongsTo<WorkType, $this> */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(related: WorkType::class);
    }

    /** @return HasMany<\App\Models\Procurement\Estimate\Resource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(related: Resource::class);
    }
}
