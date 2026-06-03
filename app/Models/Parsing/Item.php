<?php

declare(strict_types=1);

namespace App\Models\Parsing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Crawler\Crawler;
use App\Models\Platform;

final class Item extends Model
{
    use HasFactory;

    protected $table = 'plt_parsing_items';

    protected $fillable = [
        'platform_id',
        'crawler_id',
        'reg_number',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(Crawler::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ItemAttachment::class);
    }
}
