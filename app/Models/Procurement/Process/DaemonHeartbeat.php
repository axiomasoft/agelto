<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Liveness carrier каждого instance process daemon (bp-daemons); readiness считается отдельно.
 *
 * @property string $instance_id
 * @property string $daemon
 * @property string $host
 * @property int $pid
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_tick_at
 * @property string|null $last_error_code
 */
#[Table(name: 'prc_process_daemon_heartbeats', key: 'instance_id', keyType: 'string', incrementing: false, timestamps: false)]
final class DaemonHeartbeat extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'last_tick_at' => 'immutable_datetime',
        ];
    }
}
