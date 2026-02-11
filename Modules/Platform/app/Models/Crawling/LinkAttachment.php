<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LinkAttachment extends Model
{
    use HasFactory;

    protected $table = 'plt_crawler_link_attachments';

    protected $fillable = [
        'link_id',
        'name',
        'extension',
        'url',
        'status',
        'attempts',
        'last_error',
        'process_status',
        'process_error',
        'process_analysis_report',
        'parse_started_at',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    protected function casts(): array
    {
        return [
            'process_analysis_report' => 'array',
            'parse_started_at' => 'datetime',
        ];
    }
}
