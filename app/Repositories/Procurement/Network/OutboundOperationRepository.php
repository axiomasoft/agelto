<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Facades\DB;

/**
 * Writer standalone outbound owner `prc_outbound_operations` (bp-ddl-network). Это bounded owner
 * одного logical outbound call, не второй process engine: нет ready/deferred/DAG/queue/retries.
 *
 * Open снимает snapshot полного inherited control closure (FOR SHARE, sorted scope_key) как
 * evidence; authority остаётся у claimSend, который заново читает controls. Lease продлевается
 * только у open/live operation и никогда за фиксированный deadline. Close/expire — CAS open→
 * closed|expired, после которого token не оживает: queued demands cancel/expire, reserved permits
 * release/expire, sent permits и request receipts остаются фактами.
 */
final readonly class OutboundOperationRepository
{
    /**
     * @param  list<string>  $controlScopeKeys  scopes операции; ancestors добавляются автоматически
     */
    public function open(
        ProcessUuid $id,
        ProcessUuid $ownerToken,
        OutboundOperationKind $kind,
        ?int $platformId,
        string $callerCode,
        ContentHash $policyHash,
        array $controlScopeKeys,
        int $leaseSeconds,
        int $deadlineSeconds,
        ?ProcessUuid $probeToken = null,
        ?ProviderKey $providerKey = null,
        ?ProcessUuid $parentAttemptId = null,
    ): OutboundOperationHandle {
        CallerTransaction::require('Outbound operation open');
        if ($leaseSeconds < 1 || $deadlineSeconds < 1 || $controlScopeKeys === []) {
            throw new \InvalidArgumentException('Outbound operation requires a positive lease, deadline and control scopes.');
        }
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $callerCode) !== 1) {
            throw new \InvalidArgumentException('Invalid outbound caller code.');
        }
        if (($kind === OutboundOperationKind::Probe) !== ($probeToken !== null)) {
            throw new \InvalidArgumentException('Only a probe operation carries a probe token.');
        }
        if ($parentAttemptId !== null && $kind !== OutboundOperationKind::Probe) {
            throw new \InvalidArgumentException('Only a probe can be attached to a Process attempt.');
        }
        if ($providerKey !== null && ($platformId !== null || ! in_array('provider:'.$providerKey->value, $controlScopeKeys, true))) {
            throw new \InvalidArgumentException('Provider operation requires its exact provider control.');
        }
        $controls = self::lockControlClosure($controlScopeKeys);

        $row = LedgerRow::one(
            "INSERT INTO prc_outbound_operations
               (id, operation_key, kind, platform_id, provider_key, caller_code, state, owner_token, generation, lease_until, deadline_at, created_at, probe_token, policy_hash, parent_attempt_id)
             SELECT ?, ?, ?, ?, ?, ?, 'open', ?, 1, least(n.t + make_interval(secs => ?), n.t + make_interval(secs => ?)),
                     n.t + make_interval(secs => ?), n.t, ?, decode(?, 'hex'), ?
             FROM (SELECT clock_timestamp() AS t) n
             RETURNING floor(extract(epoch FROM lease_until) * 1000000)::bigint AS lease_us,
                       floor(extract(epoch FROM deadline_at) * 1000000)::bigint AS deadline_us",
            [
                $id->value, $id->value, $kind->value, $platformId, $providerKey?->value, $callerCode, $ownerToken->value,
                $leaseSeconds, $deadlineSeconds, $deadlineSeconds, $probeToken?->value, $policyHash->hex, $parentAttemptId?->value,
            ],
        ) ?? throw new \LogicException('Outbound operation insert returned no row.');
        foreach ($controls as $control) {
            DB::insert(
                'INSERT INTO prc_outbound_operation_controls (operation_id, control_scope_id, control_version) VALUES (?, ?, ?)',
                [$id->value, $control->string('id'), $control->int('control_version')],
            );
        }

        return new OutboundOperationHandle(
            $id, $kind, 1, $ownerToken, self::instant($row->int('lease_us')), self::instant($row->int('deadline_us')),
        );
    }

    /** Продление только live open operation, не дальше deadline; истёкший token → null навсегда. */
    public function renew(OutboundOperationHandle $handle, int $leaseSeconds): ?OutboundOperationHandle
    {
        CallerTransaction::require('Outbound operation renew');
        if ($leaseSeconds < 1) {
            throw new \InvalidArgumentException('Outbound operation lease must be positive.');
        }
        $row = LedgerRow::one(
            "UPDATE prc_outbound_operations
             SET lease_until = least(deadline_at, clock_timestamp() + make_interval(secs => ?))
             WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'open'
               AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()
             RETURNING floor(extract(epoch FROM lease_until) * 1000000)::bigint AS lease_us",
            [$leaseSeconds, $handle->id->value, $handle->generation, $handle->ownerToken->value],
        );

        return $row === null ? null : $handle->withLease(self::instant($row->int('lease_us')));
    }

    /** Finally-close владельцем: false — уже closed/expired (reaper успел раньше). */
    public function close(OutboundOperationHandle $handle): bool
    {
        CallerTransaction::require('Outbound operation close');
        $closed = LedgerRow::one(
            "UPDATE prc_outbound_operations SET state = 'closed', finished_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'open' RETURNING id",
            [$handle->id->value, $handle->generation, $handle->ownerToken->value],
        );
        if ($closed === null) {
            return false;
        }
        self::invalidatePending($handle->id->value, 'cancelled', 'released');

        return true;
    }

    /**
     * Crash cleanup: CAS open→expired по DB lease. Bounded batch, SKIP LOCKED между reapers.
     * Не запускает business retry и не пишет domain state.
     */
    public function expireStale(int $limit): int
    {
        CallerTransaction::require('Outbound operation expiry');
        $expired = LedgerRow::all(
            "UPDATE prc_outbound_operations o SET state = 'expired', finished_at = clock_timestamp()
             FROM (SELECT id FROM prc_outbound_operations WHERE state = 'open' AND lease_until <= clock_timestamp()
                   ORDER BY lease_until, id LIMIT ? FOR UPDATE SKIP LOCKED) stale
             WHERE o.id = stale.id RETURNING o.id",
            [max(1, $limit)],
        );
        foreach ($expired as $row) {
            self::invalidatePending($row->string('id'), 'expired', 'expired');
        }

        return count($expired);
    }

    private static function invalidatePending(string $operationId, string $demandState, string $permitState): void
    {
        DB::update(
            "UPDATE prc_network_permits p SET state = ? FROM prc_network_demands d
             WHERE p.demand_id = d.id AND d.operation_id = ? AND p.state = 'reserved'",
            [$permitState, $operationId],
        );
        DB::update(
            "UPDATE prc_network_demands SET state = ? WHERE operation_id = ? AND state = 'queued'",
            [$demandState, $operationId],
        );
    }

    /**
     * Полная ancestor closure запрошенных scopes FOR SHARE в byte-sorted порядке (tier1).
     *
     * @param  list<string>  $keys
     * @return list<LedgerRow>
     */
    private static function lockControlClosure(array $keys): array
    {
        $closure = LedgerRow::all(
            'WITH RECURSIVE chain AS (
               SELECT id, scope_key, parent_id FROM prc_control_scopes WHERE scope_key IN ('.LedgerRow::placeholders(count($keys)).')
               UNION
               SELECT parent.id, parent.scope_key, parent.parent_id FROM prc_control_scopes parent JOIN chain ON parent.id = chain.parent_id
             ) SELECT id, scope_key FROM chain ORDER BY scope_key COLLATE "C"',
            $keys,
        );
        $found = array_map(static fn (LedgerRow $row): string => $row->string('scope_key'), $closure);
        $missing = array_diff($keys, $found);
        if ($missing !== []) {
            throw new \LogicException('Outbound operation requires materialized control scopes: '.implode(', ', $missing));
        }
        $locked = [];
        foreach ($closure as $scope) {
            $locked[] = LedgerRow::one('SELECT id, control_version FROM prc_control_scopes WHERE id = ? FOR SHARE', [$scope->string('id')])
                ?? throw new \LogicException('Control scope disappeared: scope rows are never deleted.');
        }

        return $locked;
    }

    private static function instant(int $micros): \DateTimeImmutable
    {
        $seconds = intdiv($micros, 1_000_000);
        $utc = new \DateTimeZone('UTC');
        $instant = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros - $seconds * 1_000_000), $utc);

        return $instant === false ? throw new \LogicException('Invalid database instant.') : $instant->setTimezone($utc);
    }
}
