<?php

declare(strict_types=1);

namespace Sys\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'ip_address',
    ];

    // protected static function newFactory(): SysLogFactory
    // {
    //     // return SysLogFactory::new();
    // }
}
