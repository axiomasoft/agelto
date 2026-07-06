<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\OperationLogLevel;
use Database\Factories\Procurement\OperationLogFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Технический операционный журнал стадий/ошибок (партиция `prc_operation_logs`
 * RANGE by `created_at`) — не доменная история, та в `prc_events`.
 */
#[UseFactory(OperationLogFactory::class)]
final class OperationLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'prc_operation_logs';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'level',
        'stage',
        'source',
        'entity_type',
        'entity_id',
        'message',
        'context',
        'trace',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => OperationLogLevel::class,
            'entity_id' => 'integer',
            'context' => AsCollection::class,
            'created_at' => 'datetime',
        ];
    }
}
