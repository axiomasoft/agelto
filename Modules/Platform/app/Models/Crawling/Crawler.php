<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Models\Platform;

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

    /**
     * Merge options with existing and persist.
     *
     * @param  array<string, mixed>  $options
     */
    public function updateOptions(array $options): bool
    {
        return $this->update([
            'options' => array_merge($this->options ?? [], $options),
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
