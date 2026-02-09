<?php

declare(strict_types=1);

namespace Platform\Models\Parsing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ItemAttachment extends Model
{
    use HasFactory;

    protected $table = 'plt_parsing_item_attachments';

    protected $fillable = [
        'item_id',
        'name',
        'url',
        'status',
        'attempts',
        'last_error',
        'process_status',
        'process_error',
        'process_analysis_report',
        'parse_started_at',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return [
            'process_analysis_report' => 'array',
            'parse_started_at' => 'datetime',
        ];
    }
}
