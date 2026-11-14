<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Dto\Procurement\Network as N;
use App\Dto\Procurement\Process as P;
use App\Enums\Procurement\Network as E;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\TargetLink;
use App\Services\Procurement\Network\ResponseDecoder;
use App\Services\Procurement\Network\UrlPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Proxyma\Application\Execution\FiniteOutboundExecutor;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution as X;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Преобразует Process owner, parser envelope и storage artifact. Весь network loop — package. */
final readonly class CallerOutbound
{
    public function execute(N\OutboundRequestPlan $plan, P\ExecutionContext|N\OutboundExecutionContext $context,
        CallerGate $gate, UrlPolicy $policy, N\TransferBudget $budget, N\HttpRequestPayload $payload = new N\HttpRequestPayload): N\TransportOutcome
    {
        try {
            if (DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->value('authority') !== 'proxyma') {
                return new N\TransportBlocked(E\NetworkBlockedReason::ConfigurationHold);
            }
            if ($plan->bodyHash?->hex !== ($payload->body === null ? null : hash('sha256', $payload->body))) {
                throw new \InvalidArgumentException('Request body does not match pinned hash.');
            }

            return app(RuntimeCaller::class)->run($plan, $context, fn (): N\TransportOutcome => $this->send($plan, $context, $gate, $policy, $budget, $payload));
        } catch (AdmissionRejected $denied) {
            if ($denied->reason === 'owner_paused') {
                return new N\TransportBlocked(E\NetworkBlockedReason::Paused);
            }

            return new N\TransportBlocked(str_contains($denied->reason, 'owner')
                ? E\NetworkBlockedReason::OwnerLost : E\NetworkBlockedReason::ConfigurationHold);
        } catch (OutboundOwnershipLost|ExecutionInterrupted) {
            return new N\TransportBlocked(E\NetworkBlockedReason::OwnerLost);
        }
    }

    private function send(N\OutboundRequestPlan $plan, P\ExecutionContext|N\OutboundExecutionContext $context,
        CallerGate $gate, UrlPolicy $policy, N\TransferBudget $budget, N\HttpRequestPayload $payload): N\TransportOutcome
    {
        $target = DB::table((new TargetLink)->getTable())->where('tenant_key', TenantKey::SYSTEM)
            ->where('source_target_id', $plan->targetId->value)->value('target_id');
        if (! is_string($target)) {
            return new N\TransportBlocked(E\NetworkBlockedReason::ConfigurationHold);
        }
        $attempts = $plan->adapterClaim?->canary === true || $plan->trafficClass === E\TrafficClass::Diagnostic ? 1 : $plan->maxAttempts();
        $total = $plan->profile->totalSeconds * 1000;
        $timeouts = new X\RequestTimeoutPolicy(min($total, $plan->profile->connectSeconds * 1000),
            min($total, $plan->profile->lowSpeedSeconds * 1000), $total, 15_000, $attempts,
            min($attempts, $plan->profile->distinctRoutes), $plan->profile->redirects, $plan->profile->delaysMs);
        $request = new X\OutboundRequest(new X\Uuid($plan->physicalRequestId->value), new X\Uuid($target), $plan->method,
            new X\SafeUrl($plan->url->reveal()), $timeouts, X\IdempotencyClass::from($plan->idempotency->value),
            new X\PreparedHeaders($payload->headers), $payload->body === null ? null : X\WireBody::fromString($payload->body),
            sendLimit: $plan->sendLimit, responseProfile: new X\ResponseProfile($plan->profile->maxTextBytes === null ? 'binary' : 'raw',
                $plan->profile->maxEntityBytes, $plan->profile->maxEntityBytes, $plan->profile->maxTextBytes ?? $plan->profile->maxEntityBytes));
        $result = app(FiniteOutboundExecutor::class)->execute($request,
            new CallerExecutionPort($plan, $context, $gate, $policy, $budget));
        $ids = match (true) {
            $result instanceof X\ExecutionUnknownAfterAuthorization => [$result->attemptId],
            $result instanceof X\ExecutionSucceeded, $result instanceof X\ExecutionDeferred, $result instanceof X\ExecutionBlocked => $result->attemptIds,
            default => throw new \LogicException('Unsupported package execution result.'),
        };
        $last = $ids === [] ? null : new P\ProcessUuid($ids[array_key_last($ids)]->value);
        if ($result instanceof X\ExecutionSucceeded) {
            return new N\TransportSucceeded($this->response($result->response, $plan, $budget, $last), $last);
        }
        if ($result instanceof X\ExecutionDeferred) {
            $response = $result->lastDeliveredResponse === null ? null : $this->response($result->lastDeliveredResponse, $plan, $budget, $last);
            $evidence = $response !== null && $last !== null && $response->status >= 400 && $response->body instanceof N\DecodedTextBody
                ? new N\FinalHttpResponseEvidence($last, $response->status, $response->body) : null;

            return new N\TransportDeferred(E\NetworkDeferralReason::tryFrom($result->reason->value) ?? E\NetworkDeferralReason::NoRoute,
                new P\RetryAt($result->retryAt->value), $evidence, $last);
        }

        if ($result instanceof X\ExecutionBlocked && $result->reason === X\BlockedReason::EmptyProfile) {
            return new N\TransportDeferred(E\NetworkDeferralReason::NoRoute, new P\RetryAt(new \DateTimeImmutable('+1 second')), null, $last);
        }
        if ($result instanceof X\ExecutionBlocked && $this->canRevalidateStalePreauthorization($result, $plan)) {
            return new N\TransportDeferred(E\NetworkDeferralReason::DependencyUnavailable,
                new P\RetryAt(DatabaseClock::now(DB::connection())->modify('+1 second')));
        }

        return new N\TransportBlocked($result instanceof X\ExecutionBlocked
            ? (E\NetworkBlockedReason::tryFrom($result->reason->value) ?? E\NetworkBlockedReason::ConfigurationHold)
            : E\NetworkBlockedReason::PolicyReview, $last);
    }

    private function canRevalidateStalePreauthorization(X\ExecutionBlocked $result, N\OutboundRequestPlan $plan): bool
    {
        if ($result->reason !== X\BlockedReason::ConfigurationHold
            || $result->safeDetails !== ['admission_reason' => 'claim_evidence_changed']
            || $result->attemptIds !== []
            || ! in_array($plan->method, ['GET', 'HEAD'], true)
            || $plan->idempotency !== E\IdempotencyClass::ReadOnly
            || DB::transactionLevel() !== 0) {
            return false;
        }

        $tables = PackageSchema::tables();
        $operation = DB::table($tables['operations'])->where('tenant_key', TenantKey::SYSTEM)
            ->where('id', $plan->physicalRequestId->value)->first(['id', 'authorized_attempts']);
        if ($operation === null || ! is_numeric($operation->authorized_attempts) || (int) $operation->authorized_attempts !== 0
            || DB::table($tables['attempts'])->where('tenant_key', TenantKey::SYSTEM)
                ->where('operation_id', $plan->physicalRequestId->value)->exists()) {
            return false;
        }

        $permits = DB::table($tables['permits'])->where('tenant_key', TenantKey::SYSTEM)
            ->where('operation_id', $plan->physicalRequestId->value)->get(['id', 'demand_id', 'state', 'authorized_at', 'claim_id']);
        if ($permits->isEmpty() || $permits->contains(static fn (object $permit): bool => $permit->state !== 'cancelled_before_send'
            || $permit->authorized_at !== null || $permit->claim_id !== null)) {
            return false;
        }

        $permitIds = $permits->pluck('id')->all();
        $demandIds = $permits->pluck('demand_id')->all();

        return DB::table($tables['demands'])->where('tenant_key', TenantKey::SYSTEM)->whereIn('id', $demandIds)
            ->where('state', 'cancelled')->count() === count($demandIds)
            && DB::table($tables['reservations'])->whereIn('permit_id', $permitIds)->exists()
            && ! DB::table($tables['reservations'])->whereIn('permit_id', $permitIds)->where('state', '!=', 'released')->exists()
            && ! DB::table($tables['quota_reservations'])->whereIn('permit_id', $permitIds)->where('state', '!=', 'released')->exists();
    }

    private function response(X\ResponseDescriptor $response, N\OutboundRequestPlan $plan, N\TransferBudget $budget, ?P\ProcessUuid $physical): N\ResponseEnvelope
    {
        $body = $response->body;
        if ($body instanceof X\BinaryBodyArtifact) {
            try {
                $converted = $body->accept(static function ($stream, int $length, X\ContentHash $hash) use ($physical): N\BinaryBodyArtifact {
                    if (! is_resource($stream) || $physical === null) {
                        throw new \LogicException('Missing physical receipt or artifact stream.');
                    }
                    $key = 'outbound/staged/'.$physical->value;
                    if (! Storage::disk('local')->writeStream($key, $stream)) {
                        throw UnableToWriteFile::atLocation($key, 'Package artifact transfer failed.');
                    }

                    return new N\BinaryBodyArtifact(new P\ArtifactRef('local', $key, new P\ContentHash($hash->hex)), $length);
                });
            } finally {
                $body->close();
            }
            if (! $converted instanceof N\BinaryBodyArtifact) {
                throw new \LogicException('Unsupported artifact conversion result.');
            }
        } elseif ($body instanceof X\RawResponseBody) {
            $converted = app(ResponseDecoder::class)->decode($body->bytes(), $response->header('content-type'), $plan->profile);
        } else {
            throw new \LogicException('Unsupported package body.');
        }

        return new N\ResponseEnvelope($response->statusCode, $response->head->headers, $converted, $response->wireBytes, $response->decodedBytes);
    }
}
