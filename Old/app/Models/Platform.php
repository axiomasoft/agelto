<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\IgnoredLink;

// use \App\Database\Factories\PlatformFactory;

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
        return $this->hasMany(IgnoredLink::class);
    }

    // protected static function newFactory(): PlatformFactory
    // {
    //     // return PlatformFactory::new();
    // }
}
