<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Crawler extends Model
{
    use HasFactory;

    protected $table = 'plt_crawlers';

    protected $fillable = [
        'platform_id',
        'url',
        'options',
        'filter',
        'state',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(Analytics::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CrawlerRun::class);
    }

    /**
     * Merge options with existing and persist.
     *
     * @param  array<string, mixed>  $state
     */
    public function updateState(array $state): bool
    {
        return $this->update([
            'state' => array_merge($this->state ?? [], $state),
        ]);
    }

    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'options' => 'array',
            'state' => 'array',
        ];
    }
}
