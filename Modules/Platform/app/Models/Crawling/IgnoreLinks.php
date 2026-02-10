<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Models\Platform;

final class IgnoreLinks extends Model
{
    protected $table = 'plt_crawler_ignore_links';

    protected $fillable = [
        'platform_id',
        'external_id',
        'reason',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
