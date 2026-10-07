<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentSeverity;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Models\Procurement\Platform\Platform;
use Database\Factories\Procurement\Crawler\SourceIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Запись журнала инцидентов источника: пропущенный/деградировавший блок, переход брейкера,
 * ручное действие оператора. Append-only, кроме отметки «разобрано» (`acknowledged_*`).
 *
 * @property int $id
 * @property int $platform_id
 * @property int|null $crawler_id
 * @property int|null $run_id
 * @property int|null $fetch_id
 * @property SourceIncidentType $type
 * @property SourceIncidentSeverity $severity
 * @property ListingFailureKind|null $failure_kind
 * @property int|null $http_status
 * @property array<string, mixed>|null $cursor
 * @property array<string, mixed>|null $request_params
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property string|null $actor
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property Carbon|null $created_at
 * @property-read Platform|null $platform
 * @property-read Crawler|null $crawler
 */
#[UseFactory(SourceIncidentFactory::class)]
#[Fillable([
    'platform_id', 'crawler_id', 'run_id', 'fetch_id', 'type', 'severity', 'failure_kind', 'http_status',
    'cursor', 'request_params', 'message', 'context', 'actor', 'acknowledged_at', 'acknowledged_by', 'created_at',
])]
#[Table(name: 'prc_source_incidents')]
final class SourceIncident extends Model
{
    /** @use HasFactory<SourceIncidentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => SourceIncidentType::class,
            'severity' => SourceIncidentSeverity::class,
            'failure_kind' => ListingFailureKind::class,
            'http_status' => 'integer',
            'cursor' => 'array',
            'request_params' => 'array',
            'context' => 'array',
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    /** @return BelongsTo<Crawler, $this> */
    public function crawler(): BelongsTo
    {
        return $this->belongsTo(related: Crawler::class);
    }
}
