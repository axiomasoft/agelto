<?php

declare(strict_types=1);

namespace App\Models\Geo;

use App\Enums\Geo\PlaceType;
use Database\Factories\Geo\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MatanYadaev\EloquentSpatial\Objects\MultiLineString;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

final class Location extends Model
{
    use HasFactory, HasSpatial;

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     */
    protected $table = 'geo_locations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_id',
        'country_id',
        'name',
        'add_name',
        'genitive_name',
        'parent_name',
        'state',
        'slug',
        'type',
        'curator_id',
        'region_number',
        'phone_code',
        'description',
        'timezone',
        'geo_point',
        'geo_borders',
        'kladr_id',
        'fias_code',
        'fias_path',
        'fias_object_level',
        'population',
        'distance_to_big',
        'capital',
        'is_show',
    ];

    /**
     * Get the country that owns the location.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * Get the parent location.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the child locations.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PlaceType::class,
            'description' => 'array',
            'region_number' => 'integer',
            'population' => 'integer',
            'distance_to_big' => 'integer',
            'capital' => 'boolean',
            'is_show' => 'boolean',
            'geo_point' => Point::class,
            // 'geo_borders' => MultiLineString::class,
        ];
    }
}
