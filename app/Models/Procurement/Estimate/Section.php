<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use Database\Factories\Procurement\Estimate\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Дерево разделов сметы.
 *
 * @use HasFactory<SectionFactory>
 */
final class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    protected $table = 'prc_estimate_sections';

    protected $fillable = [
        'estimate_id',
        'parent_id',
        'position',
        'title',
        'section_number',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(related: Estimate::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(related: self::class, foreignKey: 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(related: self::class, foreignKey: 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(related: Item::class);
    }
}
