<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Process incident: один нерешённый на fingerprint (partial unique). Legacy prc_source_incidents
 * остаётся отдельной таблицей до P3.17.
 *
 * @property string $id
 * @property mixed $fingerprint
 * @property string $scope_key
 * @property int|null $health_epoch
 * @property string $kind
 * @property string $state
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property int $occurrences
 * @property int $transition_seq
 * @property array<string, mixed> $evidence
 * @property int $evidence_schema_version
 * @property int|null $acknowledged_by
 * @property CarbonImmutable|null $resolved_at
 */
#[Table(name: 'prc_process_incidents', keyType: 'string', incrementing: false, timestamps: false)]
final class Incident extends Model
{
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
