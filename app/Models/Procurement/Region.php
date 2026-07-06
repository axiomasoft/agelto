<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\RegionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Справочник регионов площадки (код региона на площадке → наш внутренний код).
 *
 * @use HasFactory<RegionFactory>
 */
final class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    protected $table = 'prc_regions';

    protected $fillable = [
        'platform_id',
        'external_id',
        'name',
        'region_code',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }
}
