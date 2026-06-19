<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IgnoredLink extends Model
{
    protected $table = 'plt_crawler_ignore_links';

    protected $fillable = [
        'platform_id',
        'external_id',
        'reason_category',
        'reason',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
