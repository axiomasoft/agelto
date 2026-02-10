<?php

declare(strict_types=1);

namespace Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Models\Crawling\Crawler;
use Platform\Models\Crawling\IgnoreLinks;

// use \Platform\Database\Factories\PlatformFactory;

final class Platform extends Model
{
    use HasFactory;

    protected $table = 'plt_platforms';

    protected $fillable = [
        'name',
        'code',
        'url',
    ];

    public function crawlers(): HasMany
    {
        return $this->hasMany(Crawler::class);
    }

    public function ignoreLinks(): HasMany
    {
        return $this->hasMany(IgnoreLinks::class);
    }

    // protected static function newFactory(): PlatformFactory
    // {
    //     // return PlatformFactory::new();
    // }
}
