<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\HealthAuthorization;
use App\Dto\Procurement\Network\HealthSnapshot;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\PermitEvidence;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\HealthSignal;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TargetAvailability;
use App\Enums\Telemetry\LogChannel;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\HealthPolicy;
use App\Services\Procurement\Network\HealthReducer;
use App\Services\Procurement\Network\RequestClassifier;
use App\Services\Procurement\Network\RetryPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** One factual completion transaction: health subset -> request -> observation/dedup/release.
 * No work publication. Immutable authorization IDs/epochs permit late evidence after ownership loss.
 */
final readonly class HealthRepository
{
    public function __construct(private ProcessTransaction $transaction, private RequestClassifier $classifier,
        private HealthReducer $reducer, private HealthPolicy $policy, private RetryPolicy $retryPolicy = new RetryPolicy) {}

    /** @return array<string, HealthSnapshot> Exact three-row set (empty if missing), in the same tier7 order as grants. */
    public function snapshots(string $routeId, string $targetId, bool $lock = false): array
    {
        if ($lock) {
            CallerTransaction::require('Health set lock');
            LedgerRow::one('SELECT id FROM prc_network_routes WHERE id = ? FOR UPDATE', [$routeId]);
        }
        $rows = [];
        foreach (HealthScope::cases() as $scope) {
            if ($lock && $scope === HealthScope::Target) {
                LedgerRow::one('SELECT id FROM prc_source_targets WHERE id = ? FOR UPDATE', [$targetId]);
            }
            [$where, $args] = match ($scope) {
                HealthScope::Route => ['route_id = ?', [$routeId]],
                HealthScope::RouteTarget => ['route_id = ? AND target_id = ?', [$routeId, $targetId]],
                HealthScope::Target => ['target_id = ?', [$targetId]],
            };
            $row = LedgerRow::one('SELECT * FROM '.$scope->value.' WHERE '.$where.($lock ? ' FOR UPDATE' : ''), $args);
            if ($row === null) {
                return [];
            }
            $snapshot = new HealthSnapshot($scope, $row->string('id'), CircuitState::from($row->string('state')), $row->int('health_epoch'),
                $scope === HealthScope::Target ? 0 : $row->int('consecutive_failures'), $row->int('recent_openings'), $row->nullableString('reason_code'),
                NetworkAuthorityRepository::nullableInstant($row->nullableString('open_until')), NetworkAuthorityRepository::nullableInstant($row->nullableString('stable_since')),
                $row->nullableString('probe_token'), NetworkAuthorityRepository::nullableInstant($row->nullableString('probe_until')),
                $scope === HealthScope::Target ? TargetAvailability::from($row->string('availability')) : null);
            $rows[$snapshot->key()] = $snapshot;
        }

        return $rows;
    }

    /** A credential hold is permanent for its exact credential version, not a timer. */
    public function credentialHeld(HealthSnapshot $row, string $routeId): bool
    {
        if ($row->reason !== 'credential_hold') {
            return false;
        }
        $hold = LedgerRow::one("SELECT o.details->>'credential_version' AS version, r.credential_version AS current_version
            FROM prc_network_routes r JOIN prc_request_attempts a ON a.route_id = r.id
            JOIN prc_observations o ON o.request_id = a.request_id
            WHERE r.id = ? AND o.kind = 'network_transport' AND o.reason_code = 'credential_hold' AND NOT o.stale
              AND (o.epochs->>?)::bigint < ? ORDER BY o.occurred_at DESC LIMIT 1", [$routeId, $row->key(), $row->epoch]);

        return $hold === null || $hold->string('version') === (string) $hold->int('current_version');
    }

    /** Optional maintenance evidence is adapter-confirmed, never inferred from generic HTTP 503. */
    public function record(ProcessUuid $requestId, HttpAttemptResult $result, ?\DateTimeImmutable $maintenanceUntil = null): TransportDeferred|TransportBlocked|null
    {
        $identity = LedgerRow::one('SELECT a.route_id, a.target_id, d.origin_budget_id FROM prc_request_attempts a
            LEFT JOIN prc_network_demands d ON d.id = a.demand_id WHERE a.request_id = ?', [$requestId->value])
            ?? throw new \LogicException('No durable request authorization.');

        return $this->transaction->run(function () use ($identity, $requestId, $result, $maintenanceUntil): TransportDeferred|TransportBlocked|null {
            $rows = $this->snapshots($identity->string('route_id'), $identity->string('target_id'), true);
            if ($rows === []) {
                throw new \LogicException('Missing materialized health authority.');
            }
            // Same tier7 order as grants: route/health -> target/health -> origin budget -> request/permit.
            // In particular, no committed 429 receipt/release can precede its origin send fence.
            $origin = null;
            if ($result->head?->status === 429) {
                if ($identity->nullableString('origin_budget_id') !== null) {
                    // The authorized demand pins the actual origin even if its catalog target changes later.
                    $origin = LedgerRow::one('SELECT id FROM prc_network_budgets WHERE id = ? FOR UPDATE', [$identity->string('origin_budget_id')]);
                } else {
                    $target = LedgerRow::one('SELECT canonical_origin FROM prc_source_targets WHERE id = ?', [$identity->string('target_id')])
                        ?? throw new \LogicException('Missing source target.');
                    $origin = LedgerRow::one('SELECT id FROM prc_network_budgets WHERE scope_key = ? FOR UPDATE',
                        [CanonicalOrigin::fromUrl($target->string('canonical_origin'))->budgetScopeKey()]);
                }
                if ($origin === null) {
                    throw new \LogicException('Missing origin budget.');
                }
            }
            $request = LedgerRow::one('SELECT a.*, d.operation_id, coalesce(d.operation_generation, d.work_generation, 0) AS generation
                FROM prc_request_attempts a LEFT JOIN prc_network_demands d ON d.id = a.demand_id
                WHERE a.request_id = ? FOR UPDATE OF a', [$requestId->value]) ?? throw new \LogicException('Request disappeared.');
            $previous = LedgerRow::one("SELECT details->'quota'->>'decision' AS decision, details->'quota'->>'retry_at' AS retry_at
                FROM prc_observations WHERE observation_id = ?", [$requestId->value]);
            if ($previous !== null) {
                return match ($previous->nullableString('decision')) {
                    null => null,
                    'rate_limit' => new TransportDeferred(NetworkDeferralReason::RateLimit, new RetryAt(new \DateTimeImmutable($previous->string('retry_at')))),
                    'policy_review' => new TransportBlocked(NetworkBlockedReason::PolicyReview),
                    default => throw new \LogicException('Invalid durable quota decision.'),
                };
            }
            $authorization = HealthAuthorization::decode($request->string('artifacts'), $request->int('artifacts_schema_version'));
            $classification = $this->classifier->classify($result, $authorization->kind);
            $epochs = PermitEvidence::decode($request->string('epochs'), $request->int('epochs_schema_version'));
            $now = self::now();
            $quota = null;
            if ($origin !== null) {
                $retry = $this->retryPolicy->retryAfter($result->head->header('retry-after'), $now);
                $quota = $retry === null ? new TransportBlocked(NetworkBlockedReason::PolicyReview)
                    : new TransportDeferred(NetworkDeferralReason::RateLimit, $retry);
                if ($retry !== null) {
                    DB::update('UPDATE prc_network_budgets SET not_before = greatest(not_before, ?::timestamptz), tat = greatest(tat, ?::timestamptz) WHERE id = ?',
                        [$retry->value->format('Y-m-d H:i:s.uP'), $retry->value->format('Y-m-d H:i:s.uP'), $origin->string('id')]);
                }
            }
            $stale = false;
            foreach ($rows as $key => $row) {
                if (($epochs[$key] ?? null) !== $row->epoch || ($row->state === CircuitState::HalfOpen
                    && ($authorization->probeToken !== $row->probeToken || $row->probeUntil === null || $row->probeUntil <= $now))) {
                    $stale = true;
                }
            }
            $details = ['schema_version' => 1, 'transport' => $result->metrics === null ? null : ['errno' => $result->curlCode, ...$result->metrics->values], 'route' => $classification->route->value,
                'route_target' => $classification->routeTarget->value,
                'target' => ($maintenanceUntil === null ? $classification->target : HealthSignal::Maintenance)->value,
                'independence_group' => $authorization->independenceGroup,
                'credential_version' => $authorization->credentialVersion, 'probe_token' => $authorization->probeToken,
                'http_status' => $result->head?->status,
                'retry_after' => ($header = $result->head?->header('retry-after')) !== null && strlen($header) <= 256 && preg_match('/^[\x20-\x7e]*$/D', $header) === 1 ? $header : null,
                'retry_after_sha256' => $header === null ? null : hash('sha256', $header),
                'quota' => $quota === null ? null : ['decision' => $quota->reason->value,
                    'retry_at' => $quota instanceof TransportDeferred ? $quota->retryAt->value->format('Y-m-d H:i:s.uP') : null]];
            DB::update("UPDATE prc_request_attempts SET finished_at = ?, phase = 'finished', outcome = ?, status = ?,
                duration_ms = greatest(0, ceil(extract(epoch FROM (?::timestamptz - started_at)) * 1000))::bigint, bytes = ?
                WHERE request_id = ? AND finished_at IS NULL", [$now->format('Y-m-d H:i:s.uP'), $result->failure === null ? 'response' : $result->failure->value,
                $result->head?->status, $now->format('Y-m-d H:i:s.uP'), $result->entityBytes, $requestId->value]);
            DB::insert("INSERT INTO prc_observations (observation_id, request_id, kind, reason_code, input_hash, generation, epochs,
                epochs_schema_version, stale, details, details_schema_version, occurred_at)
                VALUES (?, ?, 'network_transport', ?, decode(?, 'hex'), ?, ?::jsonb, 1, ?, ?::jsonb, 1, ?)",
                [$requestId->value, $requestId->value, $classification->reason, hash('sha256', $requestId->value), $request->int('generation'),
                    json_encode($epochs, JSON_THROW_ON_ERROR), $stale, json_encode($details, JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s.uP')]);
            foreach ($rows as $key => $row) {
                DB::insert('INSERT INTO prc_observation_applications (observation_id, projection_key, reducer_version, applied_at) VALUES (?, ?, 1, ?)',
                    [$requestId->value, $key, $now->format('Y-m-d H:i:s.uP')]);
                if ($stale) {
                    continue;
                }
                $signal = $maintenanceUntil !== null && $row->scope === HealthScope::Target ? HealthSignal::Maintenance : $classification->signal($row->scope);
                [$sample, $failures] = $this->sample($row);
                [$groups, $recent] = $this->targetEvidence($row, $identity->string('target_id'));
                $mutation = $this->reducer->reduce($row, $signal, $maintenanceUntil !== null && $row->scope === HealthScope::Target ? 'maintenance' : $classification->reason,
                    $now, $sample, $failures, $groups, $recent, $maintenanceUntil);
                if ($mutation === null) {
                    continue;
                }
                $attrs = ['state' => $mutation->state->value, 'health_epoch' => $mutation->epoch,
                    'state_version' => DB::raw('state_version + 1'), 'reason_code' => $mutation->reason,
                    'open_until' => $mutation->openUntil?->format('Y-m-d H:i:s.uP'), 'stable_since' => $mutation->stableSince?->format('Y-m-d H:i:s.uP'),
                    'recent_openings' => $mutation->openings, 'probe_token' => null, 'probe_until' => null];
                if ($row->scope === HealthScope::Target) {
                    $attrs['availability'] = $mutation->availability?->value;
                } else {
                    $attrs['consecutive_failures'] = $mutation->consecutive;
                }
                if ($signal === HealthSignal::Success) {
                    $attrs['last_success_at'] = $now->format('Y-m-d H:i:s.uP');
                } elseif ($signal !== HealthSignal::Ignored) {
                    $attrs['last_failure_at'] = $now->format('Y-m-d H:i:s.uP');
                }
                DB::table($row->scope->value)->where('id', $row->id)->update($attrs);
                if ($mutation->state === CircuitState::Open && $row->state !== CircuitState::Open) {
                    LogChannel::Network->warning('network.circuit_opened', ['scope' => $row->scope->value, 'route_id' => $identity->string('route_id'),
                        'target_id' => $identity->string('target_id'), 'reason' => $mutation->reason, 'open_until' => $mutation->openUntil?->format('Y-m-d H:i:s.uP'),
                        'consecutive_failures' => $mutation->consecutive, 'recent_openings' => $mutation->openings]);
                }
            }
            if ($request->nullableString('demand_id') !== null) {
                DB::update("UPDATE prc_network_permits SET state = 'released' WHERE demand_id = ? AND state = 'sent'", [$request->string('demand_id')]);
            }
            if ($result->failure !== null) {
                LogChannel::Network->warning('network.transport_failure', ['request_id' => $requestId->value, 'route_id' => $identity->string('route_id'),
                    'target_id' => $identity->string('target_id'), 'failure' => $result->failure->value,
                    'reason' => $result->curlCode === 28 ? 'network_timeout' : $classification->reason, 'curl_errno' => $result->curlCode]);
            }
            if ($result->metrics !== null) {
                LogChannel::Network->info('Crawler outbound attempt', [
                    'request_id' => $requestId->value, 'route_id' => $identity->string('route_id'), 'target_id' => $identity->string('target_id'),
                    'reason' => $result->curlCode === 28 ? 'network_timeout' : $classification->reason,
                    'http_status' => $result->failure === null ? $result->head?->status : null,
                    'transport' => ['errno' => $result->curlCode, ...$result->metrics->values],
                ]);
            }

            return $quota;
        });
    }

    /** @return array{int, int} Only current-epoch eligible physical outcomes form the denominator. */
    private function sample(HealthSnapshot $row): array
    {
        if ($row->scope !== HealthScope::RouteTarget) {
            return [0, 0];
        }
        $sample = LedgerRow::one("SELECT count(*) AS n, count(*) FILTER (WHERE details->>'route_target' = 'failure') AS failures
            FROM prc_observations o JOIN prc_observation_applications a ON a.observation_id = o.observation_id
            WHERE a.projection_key = ? AND NOT o.stale AND o.kind = 'network_transport' AND o.epochs->>? = ?
                AND o.occurred_at >= clock_timestamp() - make_interval(secs => ?) AND o.details->>'route_target' IN ('success','failure')",
            [$row->key(), $row->key(), (string) $row->epoch, $this->policy->targetWindow]);

        return [$sample?->int('n') ?? 0, $sample?->int('failures') ?? 0];
    }

    /** @return array{int, bool} Distinct IDs without explicit independence groups prove nothing. */
    private function targetEvidence(HealthSnapshot $row, string $targetId): array
    {
        if ($row->scope !== HealthScope::Target) {
            return [0, false];
        }
        $evidence = LedgerRow::one("SELECT count(DISTINCT nullif(o.details->>'independence_group', '')) AS groups, count(*) AS failures
            FROM prc_observations o JOIN prc_request_attempts r ON r.request_id = o.request_id
            JOIN prc_observation_applications a ON a.observation_id = o.observation_id
            WHERE r.target_id = ? AND a.projection_key = ? AND NOT o.stale AND o.kind = 'network_transport'
                AND o.epochs->>? = ? AND o.occurred_at >= clock_timestamp() - make_interval(secs => ?)
                AND o.details->>'target' IN ('failure','maintenance')", [$targetId, $row->key(), $row->key(), (string) $row->epoch, $this->policy->corroborationWindow]);

        return [$evidence?->int('groups') ?? 0, ($evidence?->int('failures') ?? 0) > 0];
    }

    public static function now(): \DateTimeImmutable
    {
        return NetworkAuthorityRepository::nowInstant();
    }
}
