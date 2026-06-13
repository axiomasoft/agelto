<?php

declare(strict_types=1);

namespace Sys\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// use \Sys\Database\Factories\SysLogFactory;

final class SysLog extends Model
{
    use HasFactory;

    protected $table = 'sys_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'level',
        'stage',
        'source',
        'entity_type',
        'entity_id',
        'message',
        'context',
        'trace',
        'ip_address',
    ];

    /**
     * Сущность журнала (Link / CrawlerRun / DocumentAnalysis и др.) по entity_type/entity_id.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(name: 'entity');
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    // protected static function newFactory(): SysLogFactory
    // {
    //     // return SysLogFactory::new();
    // }
}
