<?php

declare(strict_types=1);

namespace App\Repositories\Llm\Request;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\ProviderCall;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Exceptions\Llm\OperationDenied;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Generic persistence only: no knowledge of work, stages or analysis tables. */
final readonly class RequestRepository
{
    public function __construct(private FinancialReservationRepository $finance) {}

    public function approvedReplay(RequestIdentity $identity): bool
    {
        return ProviderRow::one("SELECT logical_request_id FROM llm_provider_requests WHERE logical_key=decode(?,'hex')
            AND replay_of IS NOT NULL AND command_id IS NOT NULL AND replay_actor IS NOT NULL AND replay_reason IS NOT NULL", [$identity->logicalKey]) !== null;
    }

    public function authorize(ProviderOperationPlan $plan, string $attempt, int $generation, string $periodId, ?ProviderOperationIds $ids = null, ?ProviderWireRequest $wire = null): AuthorizedProviderOperation
    {
        FinancialReservationRepository::requireTransaction();
        $wire?->assertOperation($plan);
        $ids ??= ProviderOperationIds::allocate();
        if (! Str::isUuid($attempt) || $generation < 1) {
            throw new \InvalidArgumentException('Invalid opaque operation owner.');
        }
        $account = $this->finance->lockAccount($plan->context->account, $plan->pricing->currency);
        $period = $this->finance->lockPeriod($periodId, $plan->context->account, $plan->pricing->currency);
        if (! $period->bool('current') || strtotime($plan->pricing->validUntil) <= time()) {
            throw new OperationDenied('pricing_or_period_expired');
        }
        $requestId = $this->prepareLocked($plan, $ids->logicalRequestId);
        $request = ProviderRow::one('SELECT state,request_epoch,max_physical_attempts,context_json::text AS context_json FROM llm_provider_requests WHERE logical_request_id=? FOR UPDATE', [$requestId])
            ?? throw new \LogicException('Missing prepared request.');
        if ($request->int('max_physical_attempts') !== $plan->maxPhysicalAttempts) {
            throw new OperationDenied('physical_budget_policy_drift');
        }
        if ($request->string('context_json') !== $plan->context->json
            && PinnedLlmContext::restore($request->string('context_json')) != $plan->context) {
            throw new OperationDenied('pinned_context_conflict');
        }
        // Duplicate authorize is not a new reserve. It can only return the same unclaimed owner.
        $prior = ProviderRow::one('SELECT physical_call_id,owner_attempt_id,owner_work_generation FROM llm_provider_calls WHERE logical_request_id=? ORDER BY ordinal DESC LIMIT 1', [$requestId]);
        if ($request->string('state') === 'sending' && $prior !== null && $prior->string('owner_attempt_id') === $attempt && $prior->int('owner_work_generation') === $generation) {
            $call = $this->get($prior->string('physical_call_id'));
            if ($call->wireFingerprint !== $wire?->fingerprint) {
                throw new OperationDenied('sealed_wire_conflict');
            }

            return $call->authorization;
        }
        if (! in_array($request->string('state'), ['prepared', 'failed_known'], true)) {
            throw new OperationDenied('request_not_retry_eligible');
        }
        $ordinal = (ProviderRow::one('SELECT count(*) AS count FROM llm_provider_calls WHERE logical_request_id=?', [$requestId])?->int('count') ?? 0) + 1;
        if ($ordinal > $request->int('max_physical_attempts')) {
            throw new OperationDenied('physical_attempt_budget_exhausted');
        }
        if ($prior !== null) {
            $previous = $this->get($prior->string('physical_call_id'));
            if (! in_array($previous->outcome, ['not_sent', ReceiptOutcome::NonbillableRetry->value, ReceiptOutcome::NonbillableCacheMiss->value], true)) {
                throw new OperationDenied('retry_charge_unproven');
            }
            if ($previous->outcome === ReceiptOutcome::NonbillableCacheMiss->value
                && (ProviderRow::one("SELECT count(*) AS count FROM llm_provider_calls WHERE logical_request_id=? AND outcome='known_nonbillable_cache_miss'", [$requestId])?->int('count') ?? 0) > 1) {
                throw new OperationDenied('uncached_retry_exhausted');
            }
        }
        $this->finance->assertSendEligible($account, $plan->pricing->upperBound);
        if (bccomp(bcadd(bcadd($period->decimal('spent'), $period->decimal('reserved'), 8), $plan->pricing->upperBound, 8), $period->decimal('cap'), 8) > 0) {
            throw new OperationDenied('provider_budget');
        }
        $provider = ProviderRow::one('SELECT id FROM llm_providers WHERE code=? AND is_active=true', [$plan->context->provider])
            ?? throw new OperationDenied('provider_configuration_hold');
        $physical = $ids->physicalCallId;
        $reservation = $ids->reservationId;
        $token = $ids->authorizationToken;
        $epoch = $request->int('request_epoch') + ($ordinal > 1 ? 1 : 0);
        DB::insert("INSERT INTO llm_provider_calls(physical_call_id,logical_request_id,ordinal,provider_id,account_id,api_surface,model,request_epoch,
            owner_attempt_id,owner_work_generation,send_authorization_token,usage_status,cost_estimate,receipt_id,receipt_state,receipt_upload_key,receipt_disk,reservation_id,wire_fingerprint)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'unknown',?,?,'pending',?,'local',?,decode(?,'hex'))", [
            $physical, $requestId, $ordinal, $provider->int('id'), $plan->context->account, $plan->context->apiVersion, $plan->context->model,
            $epoch, $attempt, $generation, $token, $plan->pricing->upperBound, $ids->receiptId, 'llm/receipts/'.$physical.'/response', $reservation, $wire?->fingerprint,
        ]);
        DB::insert("INSERT INTO llm_budget_reservations(id,operation_id,period_id,upper_bound,state,pricing_snapshot,pricing_snapshot_schema_version)
            VALUES (?,?,?,?,'reserved',?::jsonb,1)", [$reservation, $physical, $periodId, $plan->pricing->upperBound, $plan->pricing->json]);
        DB::update('UPDATE llm_budget_periods SET reserved=reserved+? WHERE id=?', [$plan->pricing->upperBound, $periodId]);
        DB::update("UPDATE llm_provider_requests SET state='sending',request_epoch=?,reservation_amount=? WHERE logical_request_id=?", [$epoch, $plan->pricing->upperBound, $requestId]);

        return new AuthorizedProviderOperation($requestId, $physical, $epoch, $token, $attempt, $generation, $reservation);
    }

    private function prepareLocked(ProviderOperationPlan $plan, string $id): string
    {
        $identity = $plan->identity;
        DB::insert("INSERT INTO llm_provider_requests(logical_request_id,logical_key,recipe_hash,input_hash,purpose,semantic_reask_ordinal,replay_intent_id,
            state,request_epoch,consumer_correlation_id,context_json,context_schema_version,max_physical_attempts)
            VALUES (?,decode(?,'hex'),decode(?,'hex'),decode(?,'hex'),?,?,?,'prepared',1,?,?::jsonb,1,?) ON CONFLICT(logical_key) DO NOTHING", [
            $id, $identity->logicalKey, $identity->recipeHash, $identity->inputHash, $identity->purpose->value, $identity->semanticReaskOrdinal,
            $identity->replayIntentId, $plan->consumerCorrelationId, $plan->context->json, $plan->maxPhysicalAttempts,
        ]);
        $row = ProviderRow::one("SELECT logical_request_id,consumer_correlation_id FROM llm_provider_requests WHERE logical_key=decode(?,'hex')", [$identity->logicalKey])
            ?? throw new \LogicException('Missing request dedup authority.');
        if ($row->string('consumer_correlation_id') !== $plan->consumerCorrelationId) {
            throw new OperationDenied('consumer_correlation_conflict');
        }

        return $row->string('logical_request_id');
    }

    public function get(string $physical): ProviderCall
    {
        $row = ProviderRow::one("SELECT c.*,encode(c.wire_fingerprint,'hex') AS wire_hex,q.purpose,q.state,encode(q.recipe_hash,'hex') AS recipe_hex,encode(q.input_hash,'hex') AS input_hex,
            q.context_json::text AS context_json,p.currency,r.period_id,r.upper_bound,encode(c.receipt_hash,'hex') AS receipt_hex
            FROM llm_provider_calls c JOIN llm_provider_requests q ON q.logical_request_id=c.logical_request_id
            JOIN llm_budget_reservations r ON r.id=c.reservation_id JOIN llm_budget_periods p ON p.id=r.period_id WHERE c.physical_call_id=?", [$physical])
            ?? throw new OperationDenied('physical_call_missing');

        return new ProviderCall(new AuthorizedProviderOperation($row->string('logical_request_id'), $physical, $row->int('request_epoch'),
            $row->string('send_authorization_token'), $row->string('owner_attempt_id'), $row->int('owner_work_generation'), $row->string('reservation_id')),
            OperationPurpose::from($row->string('purpose')), $row->string('recipe_hex'), $row->string('input_hex'),
            PinnedLlmContext::restore($row->string('context_json')), $row->string('currency'), $row->string('period_id'), $row->decimal('upper_bound'),
            $row->string('state'), $row->nullableString('send_claimed_at') !== null, $row->string('receipt_id'), $row->string('receipt_disk'),
            $row->string('receipt_upload_key'), $row->nullableString('receipt_hex'), $row->nullableString('outcome'),
            $row->nullableString('receipt_hex') === null ? null : new ReceiptAssessment(ReceiptOutcome::from($row->string('outcome')),
                $row->nullableString('cost_actual'), $row->string('charge_evidence')), $row->nullableString('wire_hex'));
    }

    public function lock(ProviderCall $expected): ProviderCall
    {
        $this->finance->lockAccount($expected->context->account, $expected->currency);
        $this->finance->lockPeriod($expected->periodId, $expected->context->account, $expected->currency);
        ProviderRow::one('SELECT logical_request_id FROM llm_provider_requests WHERE logical_request_id=? FOR UPDATE', [$expected->authorization->logicalRequestId]);
        ProviderRow::one('SELECT physical_call_id FROM llm_provider_calls WHERE physical_call_id=? FOR UPDATE', [$expected->authorization->physicalCallId]);
        $current = $this->get($expected->authorization->physicalCallId);
        if ($current->authorization != $expected->authorization || $current->recipeHash !== $expected->recipeHash || $current->inputHash !== $expected->inputHash) {
            throw new OperationDenied('operation_identity_drift');
        }

        return $current;
    }

    /** Financial half of joint-send; caller owns the full consumer prefix and network atomicity. */
    public function claimFinancialLocked(AuthorizedProviderOperation $authorization, ?string $wireFingerprint = null): bool
    {
        $call = $this->lock($this->get($authorization->physicalCallId));
        if ($call->authorization != $authorization || $call->claimed || $call->requestState !== 'sending' || $call->outcome === 'not_sent'
            || $call->wireFingerprint !== $wireFingerprint) {
            return false;
        }
        $reservation = ProviderRow::one("SELECT state,(pricing_snapshot->>'valid_until')::timestamptz > clock_timestamp() AS pricing_current
            FROM llm_budget_reservations WHERE id=? FOR UPDATE", [$call->authorization->reservationId]);
        if ($reservation === null || $reservation->string('state') !== 'reserved' || ! $reservation->bool('pricing_current')) {
            throw new OperationDenied('pricing_or_reservation_expired');
        }
        $account = $this->finance->lockAccount($call->context->account, $call->currency);
        $this->finance->assertSendEligible($account, $call->upperBound, alreadyReserved: true);

        return DB::update("UPDATE llm_provider_calls c SET send_claimed_at=clock_timestamp()
            FROM llm_provider_requests q WHERE c.physical_call_id=? AND c.send_authorization_token=? AND c.request_epoch=?
            AND c.send_claimed_at IS NULL AND c.receipt_state='pending' AND q.logical_request_id=c.logical_request_id AND q.state='sending' AND q.request_epoch=c.request_epoch",
            [$authorization->physicalCallId, $authorization->sendAuthorizationToken, $authorization->requestEpoch]) === 1;
    }

    public function interruptLocked(ProviderCall $expected): void
    {
        $call = $this->lock($expected);
        $currentEpoch = ProviderRow::one('SELECT request_epoch FROM llm_provider_requests WHERE logical_request_id=?', [$call->authorization->logicalRequestId])?->int('request_epoch');
        if ($currentEpoch !== $call->authorization->requestEpoch) {
            return;
        }
        if (! in_array($call->requestState, ['sending', 'unknown'], true) || $call->receiptHash !== null) {
            return;
        }
        if (! $call->claimed) {
            $this->finance->settleLocked($call, '0');
            DB::update("UPDATE llm_provider_calls SET receipt_state='not_sent',outcome='not_sent',cost_actual=0,usage_status='known',charge_evidence='unclaimed_authorization_revoked' WHERE physical_call_id=?", [$call->authorization->physicalCallId]);
            DB::update("UPDATE llm_provider_requests SET state='failed_known',request_epoch=request_epoch+1 WHERE logical_request_id=?", [$call->authorization->logicalRequestId]);
        } else {
            DB::update("UPDATE llm_provider_calls SET receipt_state='unknown',outcome='unknown' WHERE physical_call_id=?", [$call->authorization->physicalCallId]);
            DB::update("UPDATE llm_provider_requests SET state='unknown' WHERE logical_request_id=?", [$call->authorization->logicalRequestId]);
        }
    }

    public function acceptReceiptLocked(VerifiedReceipt $receipt): void
    {
        $call = $this->lock($receipt->call);
        if (! $call->claimed || $call->outcome === 'not_sent') {
            throw new OperationDenied('receipt_without_send_claim');
        }
        if ($call->receiptHash !== null) {
            if ($call->receiptHash !== $receipt->hash) {
                throw new OperationDenied('immutable_receipt_conflict');
            }

            return;
        }
        $assessment = $receipt->assessment;
        $unknown = $assessment->outcome === ReceiptOutcome::Unknown;
        $amount = $unknown ? null : ($assessment->cost ?? $call->upperBound);
        if ($amount !== null) {
            $this->finance->settleLocked($call, $amount, $receipt->hash);
        }
        DB::update("UPDATE llm_provider_calls SET receipt_state='verified',receipt_key=receipt_upload_key,receipt_hash=decode(?,'hex'),receipt_schema_version=1,
            http_status=?,response_at=clock_timestamp(),outcome=?,charge_evidence=?,cost_actual=?,usage_status=? WHERE physical_call_id=?", [
            $receipt->hash, $receipt->status, $assessment->outcome->value, $assessment->policyEvidence, $amount, $unknown ? 'unknown' : 'known', $call->authorization->physicalCallId,
        ]);
        $state = match ($assessment->outcome) {
            ReceiptOutcome::Success => 'completed',
            ReceiptOutcome::NonbillableCacheMiss, ReceiptOutcome::NonbillableRetry, ReceiptOutcome::BilledError => 'failed_known',
            ReceiptOutcome::Unknown => 'unknown',
        };
        DB::update('UPDATE llm_provider_requests SET state=? WHERE logical_request_id=?', [$state, $call->authorization->logicalRequestId]);
    }

    /** A command creates an audited durable replay intent, never releases the old obligation. */
    public function replay(ProviderCall $original, string $commandId, string $actor, string $reason): RequestIdentity
    {
        if (! Str::isUuid($commandId) || $actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Explicit replay approval required.');
        }

        return DB::transaction(function () use ($original, $commandId, $actor, $reason): RequestIdentity {
            $call = $this->lock($original);
            $existing = ProviderRow::one('SELECT *,encode(recipe_hash,\'hex\') AS recipe_hex,encode(input_hash,\'hex\') AS input_hex FROM llm_provider_requests WHERE command_id=?', [$commandId]);
            if ($existing !== null) {
                if ($existing->string('replay_of') !== $call->authorization->logicalRequestId || $existing->string('replay_actor') !== $actor || $existing->string('replay_reason') !== $reason) {
                    throw new OperationDenied('replay_command_conflict');
                }

                return new RequestIdentity($existing->string('recipe_hex'), $existing->string('input_hex'), OperationPurpose::from($existing->string('purpose')), $existing->int('semantic_reask_ordinal'), $existing->string('replay_intent_id'));
            }
            if ($call->receiptHash !== null || ! in_array($call->requestState, ['unknown', 'abandoned_unknown'], true)) {
                throw new OperationDenied('replay_requires_unresolved_missing_receipt');
            }
            $old = ProviderRow::one('SELECT semantic_reask_ordinal,consumer_correlation_id,max_physical_attempts FROM llm_provider_requests WHERE logical_request_id=?', [$call->authorization->logicalRequestId])
                ?? throw new \LogicException('Missing replay source.');
            $identity = new RequestIdentity($call->recipeHash, $call->inputHash, $call->purpose, $old->int('semantic_reask_ordinal'), (string) Str::uuid());
            DB::insert("INSERT INTO llm_provider_requests(logical_request_id,logical_key,recipe_hash,input_hash,purpose,semantic_reask_ordinal,replay_intent_id,replay_of,
                command_id,replay_actor,replay_reason,state,request_epoch,consumer_correlation_id,context_json,context_schema_version,max_physical_attempts)
                VALUES (?,decode(?,'hex'),decode(?,'hex'),decode(?,'hex'),?,?,?,?,?,?,?,'prepared',1,?,?::jsonb,1,?)", [
                (string) Str::uuid(), $identity->logicalKey, $identity->recipeHash, $identity->inputHash, $identity->purpose->value, $identity->semanticReaskOrdinal,
                $identity->replayIntentId, $call->authorization->logicalRequestId, $commandId, $actor, $reason, $old->string('consumer_correlation_id'), $call->context->json, $old->int('max_physical_attempts'),
            ]);

            return $identity;
        });
    }

    /** Abandon retains the unknown reserve; conservative settlement explicitly charges its full bound. */
    public function abandon(ProviderCall $expected, string $actor, string $reason, bool $conservativelySettle = false): void
    {
        if ($actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Audited financial action required.');
        }
        DB::transaction(function () use ($expected, $actor, $reason, $conservativelySettle): void {
            $call = $this->lock($expected);
            if (! in_array($call->requestState, ['unknown', 'abandoned_unknown'], true)) {
                throw new OperationDenied('only_unknown_can_be_abandoned');
            }
            if ($conservativelySettle) {
                $this->finance->settleLocked($call, $call->upperBound);
            }
            DB::update("UPDATE llm_provider_requests SET state='abandoned_unknown' WHERE logical_request_id=?", [$call->authorization->logicalRequestId]);
            DB::insert('INSERT INTO llm_financial_actions(id,account_id,currency,physical_call_id,action,actor,reason,details,details_schema_version)
                VALUES (?,?,?,?,?,?,?,?::jsonb,1)', [(string) Str::uuid(), $call->context->account, $call->currency, $call->authorization->physicalCallId,
                $conservativelySettle ? 'conservative_settlement' : 'abandon_unknown', $actor, $reason,
                CanonicalJson::encode(['original_period_id' => $call->periodId, 'upper_bound' => $call->upperBound])]);
        });
    }
}
