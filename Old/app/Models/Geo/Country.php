<?php

declare(strict_types=1);

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Country extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * The table associated with the model.
     */
    protected $table = 'geo_countries';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'add_name',
        'slug',
    ];

    /**
     * Get the locations for the country.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'country_id');
    }
}
