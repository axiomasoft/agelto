<?php

declare(strict_types=1);

namespace App\Repositories\Llm\Request;

use App\Dto\Llm\Requests\FinancialAmount;
use App\Dto\Llm\Requests\ProviderCall;
use App\Dto\Llm\Requests\UnknownOperationPolicy;
use App\Exceptions\Llm\OperationDenied;
use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class FinancialReservationRepository
{
    /** Pre-read only. Authorization revalidates this period under the account/period locks. */
    public function currentPeriod(string $account, string $currency): string
    {
        return ProviderRow::one('SELECT id FROM llm_budget_periods WHERE account_id=? AND currency=?
            AND period_start<=clock_timestamp() AND period_end>clock_timestamp()', [$account, $currency])?->string('id')
            ?? throw new OperationDenied('provider_budget');
    }

    public static function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Financial operation requires caller transaction.');
        }
    }

    public function configureAccount(string $account, string $currency, int $threshold, ?string $hardExposure, string $actor, string $reason): void
    {
        $policy = new UnknownOperationPolicy($threshold, $hardExposure);
        $hardExposure = $policy->hardExposureCap?->value;
        if ($account === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $threshold < 1 || ($hardExposure !== null && bccomp($hardExposure, '0', 8) < 0) || $actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Invalid audited financial policy.');
        }
        DB::transaction(function () use ($account, $currency, $threshold, $hardExposure, $actor, $reason): void {
            DB::insert('INSERT INTO llm_budget_accounts(account_id,currency,unknown_count_threshold,hard_exposure_cap,policy_actor,policy_reason)
                VALUES (?,?,?,?,?,?) ON CONFLICT DO NOTHING', [$account, $currency, $threshold, $hardExposure, $actor, $reason]);
            $this->lockAccount($account, $currency);
            DB::update('UPDATE llm_budget_accounts SET unknown_count_threshold=?,hard_exposure_cap=?,policy_version=policy_version+1,policy_actor=?,policy_reason=?
                WHERE account_id=? AND currency=?', [$threshold, $hardExposure, $actor, $reason, $account, $currency]);
            $policy = $this->lockAccount($account, $currency);
            DB::insert("INSERT INTO llm_financial_actions(id,account_id,currency,action,actor,reason,details,details_schema_version)
                VALUES (?,?,?,'policy_change',?,?,?::jsonb,1)", [(string) Str::uuid(), $account, $currency, $actor, $reason,
                CanonicalJson::encode(['version' => $policy->int('policy_version'), 'unknown_threshold' => $threshold, 'hard_exposure_cap' => $hardExposure, 'automatic_resend_unknown' => false])]);
        });
    }

    public function openPeriod(string $account, string $currency, string $start, string $end, string $cap): string
    {
        $cap = FinancialAmount::normalize($cap);
        if (bccomp($cap, '0', 8) < 0 || strtotime($start) === false || strtotime($end) === false || strtotime($start) >= strtotime($end)) {
            throw new \InvalidArgumentException('Invalid budget period.');
        }

        return DB::transaction(function () use ($account, $currency, $start, $end, $cap): string {
            $this->lockAccount($account, $currency);
            $existing = ProviderRow::one('SELECT id,cap FROM llm_budget_periods WHERE account_id=? AND currency=? AND period_start=?::timestamptz AND period_end=?::timestamptz', [$account, $currency, $start, $end]);
            if ($existing !== null) {
                if (bccomp($existing->decimal('cap'), $cap, 8) !== 0) {
                    throw new OperationDenied('period_cap_conflict');
                }

                return $existing->string('id');
            }
            if (ProviderRow::one('SELECT id FROM llm_budget_periods WHERE account_id=? AND currency=? AND period_start<?::timestamptz AND period_end>?::timestamptz', [$account, $currency, $end, $start]) !== null) {
                throw new OperationDenied('overlapping_budget_period');
            }
            $id = (string) Str::uuid();
            DB::insert('INSERT INTO llm_budget_periods(id,account_id,currency,period_start,period_end,cap) VALUES (?,?,?,?::timestamptz,?::timestamptz,?)', [$id, $account, $currency, $start, $end, $cap]);

            return $id;
        });
    }

    public function lockAccount(string $account, string $currency): ProviderRow
    {
        self::requireTransaction();

        return ProviderRow::one('SELECT * FROM llm_budget_accounts WHERE account_id=? AND currency=? FOR UPDATE', [$account, $currency])
            ?? throw new OperationDenied('account_policy_missing');
    }

    public function lockPeriod(string $id, string $account, string $currency): ProviderRow
    {
        return ProviderRow::one('SELECT *, period_start<=clock_timestamp() AND period_end>clock_timestamp() AS current FROM llm_budget_periods WHERE id=? AND account_id=? AND currency=? FOR UPDATE', [$id, $account, $currency])
            ?? throw new OperationDenied('budget_period_missing');
    }

    public function assertSendEligible(ProviderRow $account, string $newExposure, bool $alreadyReserved = false): void
    {
        $newExposure = FinancialAmount::normalize($newExposure);
        $bindings = [$account->string('account_id'), $account->string('currency')];
        $unknown = ProviderRow::one("SELECT count(DISTINCT q.logical_request_id) AS count FROM llm_provider_requests q
            JOIN llm_provider_calls c ON c.logical_request_id=q.logical_request_id
            JOIN llm_budget_reservations r ON r.id=c.reservation_id JOIN llm_budget_periods p ON p.id=r.period_id
            WHERE p.account_id=? AND p.currency=? AND q.state IN ('unknown','abandoned_unknown')", $bindings)?->int('count') ?? 0;
        if (bccomp($newExposure, '0', 8) > 0 && $unknown >= $account->int('unknown_count_threshold')) {
            throw new OperationDenied('account_unknown_hold');
        }
        $exposure = ProviderRow::one("SELECT COALESCE(sum(r.upper_bound),0) AS amount FROM llm_budget_reservations r
            JOIN llm_budget_periods p ON p.id=r.period_id JOIN llm_provider_calls c ON c.physical_call_id=r.operation_id
            JOIN llm_provider_requests q ON q.logical_request_id=c.logical_request_id
            WHERE p.account_id=? AND p.currency=? AND (r.state='reserved' OR (c.usage_status='unknown' AND q.state IN ('unknown','abandoned_unknown')))", $bindings)?->decimal('amount') ?? '0';
        $hardCap = $account->nullableString('hard_exposure_cap');
        $hardCap = $hardCap === null ? null : FinancialAmount::normalize($hardCap);
        if ($hardCap !== null && bccomp(bcadd($exposure, $alreadyReserved ? '0' : $newExposure, 8), $hardCap, 8) > 0) {
            throw new OperationDenied('account_exposure_hold');
        }
    }

    public function settleLocked(ProviderCall $call, string $amount, ?string $verifiedReceiptHash = null): void
    {
        $amount = FinancialAmount::normalize($amount);
        self::requireTransaction();
        if (bccomp($amount, '0', 8) < 0 || bccomp($amount, $call->upperBound, 8) > 0) {
            throw new OperationDenied('usage_exceeds_reserved_bound');
        }
        $row = ProviderRow::one('SELECT state,settled_amount FROM llm_budget_reservations WHERE id=? FOR UPDATE', [$call->authorization->reservationId])
            ?? throw new \LogicException('Missing reservation.');
        if ($row->string('state') === 'settled') {
            if (bccomp($row->decimal('settled_amount'), $amount, 8) !== 0) {
                if ($verifiedReceiptHash === null || preg_match('/^[a-f0-9]{64}$/D', $verifiedReceiptHash) !== 1
                    || ProviderRow::one("SELECT id FROM llm_financial_actions WHERE physical_call_id=? AND action='conservative_settlement'", [$call->authorization->physicalCallId]) === null) {
                    throw new OperationDenied('settlement_conflict');
                }
                DB::insert("INSERT INTO llm_financial_actions(id,account_id,currency,physical_call_id,action,actor,reason,details,details_schema_version)
                    VALUES (?,?,?,?,'receipt_reconciliation','verified_receipt','Resolve conservative settlement with exact receipt',?::jsonb,1)", [
                    (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'llm-receipt-settlement:'.$call->authorization->physicalCallId.':'.$verifiedReceiptHash),
                    $call->context->account, $call->currency, $call->authorization->physicalCallId,
                    CanonicalJson::encode(['receipt_sha256' => $verifiedReceiptHash, 'previous_amount' => $row->decimal('settled_amount'), 'settled_amount' => $amount]),
                ]);
                DB::update('UPDATE llm_budget_periods SET spent=spent-?+? WHERE id=?', [$row->decimal('settled_amount'), $amount, $call->periodId]);
                DB::update('UPDATE llm_budget_reservations SET settled_amount=? WHERE id=?', [$amount, $call->authorization->reservationId]);
            }

            return;
        }
        DB::update('UPDATE llm_budget_periods SET reserved=reserved-?,spent=spent+? WHERE id=?', [$call->upperBound, $amount, $call->periodId]);
        DB::update("UPDATE llm_budget_reservations SET state='settled',settled_amount=? WHERE id=?", [$amount, $call->authorization->reservationId]);
    }
}
