<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Caller;

use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;

/** Тот же trusted subject resolver и panel/slot, что у embedded forms. */
final readonly class CallerPolicyContext
{
    public function __construct(public SubjectChain $subjects, public string $panel, public string $slot) {}

    public static function resolve(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): self
    {
        $source = LedgerRow::one('SELECT platform_id, provider_key, capability FROM prc_source_targets WHERE id=? AND retired_at IS NULL', [$plan->targetId->value])
            ?? throw new AdmissionRejected('target_missing');
        [$panel, $slot] = match ($source->string('capability')) {
            'listing' => ['agelto.acquisition', 'listing'],
            'detail' => ['agelto.acquisition', $plan->trafficClass->value === 'status_refresh' ? 'status_refresh' : 'detail'],
            'download' => ['agelto.document', 'download'],
            'reference_sync' => ['agelto.reference', 'reference'],
            'notification' => ['agelto.notification', 'notification'],
            'provider' => ['agelto.provider', 'llm'],
            default => throw new AdmissionRejected('target_capability_unknown'),
        };
        $tenant = TenantKey::system();
        $platform = $source->nullableInt('platform_id');
        $provider = $source->nullableString('provider_key');
        $leaf = $platform === null ? new SubjectRef($tenant, 'system', 'network') : new SubjectRef($tenant, 'platform', (string) $platform);
        if ($provider !== null && $panel === 'agelto.provider') {
            $leaf = new SubjectRef($tenant, 'provider', $provider);
        }
        $lease = $context->lease();
        $workId = $lease instanceof ExecutionLease ? $lease->workId->value->value
            : LedgerRow::one('SELECT a.work_id FROM prc_outbound_operations o JOIN prc_process_attempts a ON a.id=o.parent_attempt_id WHERE o.id=?', [$lease->id->value])?->string('work_id');
        if ($workId !== null && $platform !== null) {
            $owner = LedgerRow::one('SELECT r.profile_id, COALESCE(w.procurement_id,d.procurement_id) AS procurement_id
                FROM prc_process_work w LEFT JOIN prc_crawl_blocks b ON b.id=w.crawl_block_id
                LEFT JOIN prc_crawl_runs c ON c.id=b.crawl_run_id
                LEFT JOIN prc_process_runs r ON r.id=COALESCE(w.origin_run_id,c.process_run_id)
                LEFT JOIN prc_documents d ON d.id=w.document_id WHERE w.id=?', [$workId]);
            if ($owner?->nullableInt('profile_id') !== null) {
                $leaf = new SubjectRef($tenant, 'crawler', (string) $owner->int('profile_id'));
            } elseif ($owner?->nullableInt('procurement_id') !== null) {
                $leaf = new SubjectRef($tenant, 'procurement', (string) $owner->int('procurement_id'));
            }
        }
        $chain = app(BindingAdapter::class)->resolve($leaf) ?? throw new AdmissionRejected('owner_lost');
        if ($platform !== null && ! array_any($chain->ordered, static fn (SubjectRef $ref): bool => $ref->type === 'platform' && $ref->id === (string) $platform)) {
            throw new AdmissionRejected('owner_lost');
        }

        return new self($chain, $panel, $slot);
    }
}
