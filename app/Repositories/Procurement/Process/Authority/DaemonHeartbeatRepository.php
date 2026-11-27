<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\DaemonHealth;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use Illuminate\Support\Facades\DB;

/**
 * Writer/reader prc_process_daemon_heartbeats. Heartbeat — liveness loop: время всегда
 * DB clock, не PHP. Readiness отдельно: last_error_code хранит текущую dependency/processing ошибку
 * и сбрасывается первым чистым tick. Authority над work это не даёт: таблица только для health.
 */
final readonly class DaemonHeartbeatRepository
{
    /** Instance, молчащий дольше этого срока, — след прошлого рестарта, а не живой daemon. */
    private const int STALE_RETENTION_SECONDS = 86_400;

    public function register(ProcessUuid $instance, DaemonKind $kind, ?string $errorCode = null): void
    {
        DB::delete(
            'DELETE FROM prc_process_daemon_heartbeats WHERE last_tick_at < clock_timestamp() - make_interval(secs => ?)',
            [self::STALE_RETENTION_SECONDS],
        );
        DB::insert(
            'INSERT INTO prc_process_daemon_heartbeats (instance_id, daemon, host, pid, started_at, last_tick_at, last_error_code)
             VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp(), ?)
             ON CONFLICT (instance_id) DO UPDATE SET last_tick_at = clock_timestamp(), last_error_code = EXCLUDED.last_error_code',
            [$instance->value, $kind->value, gethostname() ?: 'unknown', getmypid() ?: 0, $errorCode],
        );
    }

    /** false — строки нет: loop повторит регистрацию живого instance перед следующим heartbeat. */
    public function beat(ProcessUuid $instance, ?string $errorCode): bool
    {
        return DB::update(
            'UPDATE prc_process_daemon_heartbeats SET last_tick_at = clock_timestamp(), last_error_code = ? WHERE instance_id = ?',
            [$errorCode, $instance->value],
        ) === 1;
    }

    /** Graceful stop: instance исчезает, а не изображает живой daemon до истечения live window. */
    public function retire(ProcessUuid $instance): void
    {
        DB::delete('DELETE FROM prc_process_daemon_heartbeats WHERE instance_id = ?', [$instance->value]);
    }

    /**
     * Healthy — есть живой instance без текущей ошибки; Degraded — живые instances есть, но все с
     * ошибкой; Down — ни одного instance со свежим last_tick_at. Сравнение с интервалом только в SQL:
     * PHP время в решении не участвует.
     */
    public function health(DaemonKind $kind, int $liveSeconds): DaemonHealth
    {
        $row = LedgerRow::one(
            'SELECT count(*) AS live, count(*) FILTER (WHERE last_error_code IS NULL) AS clean
             FROM prc_process_daemon_heartbeats
             WHERE daemon = ? AND last_tick_at >= clock_timestamp() - make_interval(secs => ?)',
            [$kind->value, $liveSeconds],
        ) ?? throw new \LogicException('Aggregate query always returns a row.');

        return match (true) {
            $row->int('live') === 0 => DaemonHealth::Down,
            $row->int('clean') === 0 => DaemonHealth::Degraded,
            default => DaemonHealth::Healthy,
        };
    }
}
