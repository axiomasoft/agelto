<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Dto\Procurement\Acquisition\ProcurementIdentity;
use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\TransitionRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProcurementStoreRepository
{
    public function storeProcurement(ProcurementSnapshotData $data, string $status, Carbon $seenAt, ProcurementIdentity $identity): Procurement
    {
        CallerTransaction::require('Canonical identity insert');
        if ($data->identity->toArray() !== $identity->source->toArray() || $data->registryId !== $identity->registryId) {
            throw new \LogicException('Identity binding mismatch.');
        }
        $model = new Procurement([
            'uuid' => (string) Str::uuid7(),
            'registry_id' => $data->registryId,
            'platform_id' => $identity->platformId,
            'source_identity_type' => $identity->source->type->value,
            'source_identity_value' => $identity->source->value->value,
            'title' => $data->title,
            'status' => $status,
            'source_stage' => $data->sourceStage,
            'procedure_type' => $data->procedureType,
            'procedure_type_raw' => $data->procedureTypeRaw,
            'initial_price' => $data->initialPrice,
            'currency' => $data->currency,
            'advance_percent' => $data->advancePercent,
            'okved_code' => $data->okvedCode,
            'okved_additional' => $data->okvedAdditional,
            'region_code' => $data->regionCode,
            'published_at' => $data->publishedAt,
            'source_updated_at' => $data->sourceUpdatedAt,
            'source_updated_has_day_precision' => $data->sourceUpdatedAtHasDayPrecision,
            'source_updated_before' => $data->sourceUpdatedBefore,
            'bidding_starts_at' => $data->biddingStartsAt,
            'bidding_ends_at' => $data->biddingEndsAt,
            'summary_at' => $data->summaryAt,
            'detail_url' => $data->detailUrl,
            'attributes' => $data->attributes,
            'revision_count' => 1,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
        $model->updateTimestamps();
        $attributes = $model->getAttributes();
        $query = DB::table('prc_procurements');
        $sql = DB::connection()->getQueryGrammar()->compileInsert($query, $attributes);
        $inserted = DB::selectOne($sql.' ON CONFLICT ON CONSTRAINT prc_procurements_source_identity_unique DO NOTHING RETURNING id', array_values($attributes));
        $procurement = app(ProcurementReadRepository::class)->findProcurement($identity, lock: true)
            ?? throw new \LogicException('Identity insert did not yield a row.');
        $procurement->wasRecentlyCreated = $inserted !== null;

        return $procurement;
    }

    public function updateProcurementHead(
        Procurement $procurement,
        ProcurementSnapshotData $data,
        string $status,
        ProcurementRevision $revision,
        int $revisionCount,
        Carbon $seenAt,
        ?Carbon $observedAt = null,
    ): void {
        $procurement->update([
            'source_order_ambiguous' => $data->sourceUpdatedAtHasDayPrecision,
            'title' => $data->title,
            'status' => $status,
            'source_stage' => $data->sourceStage,
            'procedure_type' => $data->procedureType,
            'procedure_type_raw' => $data->procedureTypeRaw,
            'initial_price' => $data->initialPrice,
            'currency' => $data->currency,
            'advance_percent' => $data->advancePercent,
            'okved_code' => $data->okvedCode,
            'okved_additional' => $data->okvedAdditional,
            'region_code' => $data->regionCode,
            'source_updated_at' => $data->sourceUpdatedAt,
            'source_updated_has_day_precision' => $data->sourceUpdatedAtHasDayPrecision,
            'source_updated_before' => $data->sourceUpdatedBefore,
            'bidding_starts_at' => $data->biddingStartsAt,
            'bidding_ends_at' => $data->biddingEndsAt,
            'summary_at' => $data->summaryAt,
            'detail_url' => $data->detailUrl,
            'attributes' => $data->attributes,
            'current_revision_id' => $revision->id,
            'current_revision_detected_at' => $revision->detected_at,
            'revision_count' => $revisionCount,
            'last_seen_at' => $seenAt,
        ]);
        // A different accepted head owns its capture time even when source intervals prove
        // it newer than a response captured later. Monotonic observation applies only to
        // subsequent confirmations of the same content.
        DB::table($procurement->getTable())->where('id', $procurement->id)->update([
            'head_generation' => $procurement->head_generation + ($revisionCount > 1 ? 1 : 0),
            'source_observed_at' => ($observedAt ?? $seenAt)->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function recordObservation(Procurement $procurement, ProcurementSnapshotData $data, Carbon $observedAt): void
    {
        // Query builder preserves microseconds; the model's legacy date serialization does not.
        DB::table($procurement->getTable())->where('id', $procurement->id)
            ->where(fn (Builder $query) => $query->whereNull('source_observed_at')->orWhere('source_observed_at', '<=', $observedAt->format('Y-m-d H:i:s.uP')))
            ->update([
                'source_observed_at' => $observedAt->format('Y-m-d H:i:s.uP'),
                'source_updated_has_day_precision' => $data->sourceUpdatedAtHasDayPrecision,
                'source_updated_before' => $data->sourceUpdatedBefore,
                'source_order_ambiguous' => $data->sourceUpdatedAtHasDayPrecision,
            ]);
    }

    public function touchLastSeen(Procurement $procurement, Carbon $seenAt): void
    {
        $procurement->update(['last_seen_at' => $seenAt]);
    }

    /**
     * Атомарно фиксирует первый положительный исход. Колонка, а не `prc_events`, является
     * носителем идемпотентности доменного события.
     */
    public function markSucceeded(Procurement $procurement, Carbon $succeededAt): bool
    {
        return Procurement::query()
            ->whereKey($procurement->id)
            ->whereNull('succeeded_at')
            ->update(['succeeded_at' => $succeededAt]) === 1;
    }

    /**
     * Детальный слой снят успешно (`02-crawling.md` §5.2) — error-блок сбрасывается,
     * повторный успешный снимок «лечит» ранее накопленные отказы.
     *
     * @param  array<string, mixed>|null  $detailParseOutcome
     */
    public function markDetailFetched(Procurement $procurement, Carbon $fetchedAt, ?array $detailParseOutcome = null): void
    {
        $attributes = [
            'detail_fetched_at' => $fetchedAt,
            'detail_attempts' => 0,
            'detail_last_error' => null,
            'detail_next_retry_at' => null,
            'detail_failed' => false,
        ];

        if ($detailParseOutcome !== null) {
            $attributes['detail_parse_outcome'] = $detailParseOutcome;
        }

        $procurement->update($attributes);
    }

    /**
     * Снятие детального слоя упало (`01-database.md` §11 «Детальная карточка упала») —
     * error-блок `detail_*`, ретрай по `detail_next_retry_at` без реконструкции из логов.
     */
    /** @param array<string, mixed>|null $detailParseOutcome */
    public function markDetailFailed(
        Procurement $procurement,
        string $error,
        ?Carbon $nextRetryAt,
        bool $failed,
        ?array $detailParseOutcome = null,
    ): void {
        DB::transaction(function () use ($procurement, $error, $nextRetryAt, $failed, $detailParseOutcome): void {
            $locked = Procurement::query()->whereKey($procurement->id)->lockForUpdate()->firstOrFail();
            $attributes = [
                'detail_attempts' => $locked->detail_attempts + 1,
                'detail_last_error' => $error,
                'detail_next_retry_at' => $nextRetryAt,
                'detail_failed' => $failed,
            ];
            if ($detailParseOutcome !== null) {
                $input = $locked->detail_parse_outcome['input_validation'] ?? null;
                $attributes['detail_parse_outcome'] = $input === null ? $detailParseOutcome
                    : [...$detailParseOutcome, 'input_validation' => $input];
            }
            $locked->update($attributes);
            $procurement->refresh();
        });
    }

    /** Contract-only retry budget is local to this input; it never consumes detail_attempts. */
    public function recordDetailContractFailure(Procurement $procurement, AdapterContractRef $contract,
        string $checkCode, int $limit): void
    {
        DB::transaction(function () use ($procurement, $contract, $checkCode, $limit): void {
            $locked = Procurement::query()->whereKey($procurement->id)->lockForUpdate()->firstOrFail();
            $outcome = $locked->detail_parse_outcome ?? [];
            $old = $outcome['input_validation'] ?? [];
            $count = ($old['contract'] ?? null) == $contract->toArray() && is_int($old['failures'] ?? null) ? $old['failures'] + 1 : 1;
            $outcome['input_validation'] = ['contract' => $contract->toArray(), 'failures' => $count,
                'held' => $count >= $limit, 'check_code' => $checkCode];
            $locked->update(['detail_parse_outcome' => $outcome, 'detail_failed' => $count >= $limit,
                'detail_next_retry_at' => $count >= $limit ? null : now()->addSeconds(30 * $count),
                'detail_last_error' => 'Adapter input rejected: '.$checkCode]);
            $procurement->refresh();
        });
    }

    /** Manual input recovery retains the original pure-contract history in operator audit. */
    public function resetDetailInput(Procurement $procurement, int $actorId, string $reason): void
    {
        CallerTransaction::require('Detail input recovery');
        if ($actorId < 1 || trim($reason) === '') {
            throw new \InvalidArgumentException('Detail input recovery requires an actor and reason.');
        }
        $system = LedgerRow::one("SELECT id, desired_mode, control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")
            ?? throw new \LogicException('Control authority is missing.');
        $locked = Procurement::query()->whereKey($procurement->id)->lockForUpdate()->firstOrFail();
        $outcome = $locked->detail_parse_outcome ?? [];
        unset($outcome['input_validation']);
        $locked->update(['detail_parse_outcome' => $outcome, 'detail_failed' => false, 'detail_attempts' => 0,
            'detail_next_retry_at' => null, 'detail_last_error' => null]);
        $version = $system->int('control_version') + 1;
        DB::update('UPDATE prc_control_scopes SET control_version = ? WHERE id = ?', [$version, $system->string('id')]);
        app(TransitionRepository::class)->recordControl(
            new ProcessUuid((string) Str::uuid()), new ProcessUuid((string) Str::uuid()),
            new ProcessUuid($system->string('id')), $version, $system->string('desired_mode'),
            $system->string('desired_mode'), 'detail_input_reset', ActorKind::Operator,
            $actorId, ['reason' => $reason, 'procurement_id' => $procurement->id]);
        $procurement->refresh();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function storeRevision(
        int $procurementId,
        int $revisionNumber,
        array $snapshot,
        string $snapshotHash,
        Carbon $detectedAt,
        ?Carbon $sourceUpdatedAt = null,
        ?int $crawlerRunId = null,
        ?string $sourceEdition = null,
        ?string $changeReason = null,
    ): ProcurementRevision {
        return ProcurementRevision::query()->create([
            'procurement_id' => $procurementId,
            'revision_number' => $revisionNumber,
            'source_edition' => $sourceEdition,
            'change_reason' => $changeReason,
            'snapshot' => $snapshot,
            'snapshot_hash' => $snapshotHash,
            'source_updated_at' => $sourceUpdatedAt,
            'detected_at' => $detectedAt,
            'crawler_run_id' => $crawlerRunId,
        ]);
    }

    public function hasEvent(
        int $procurementId,
        ProcurementEventType $eventType,
        Carbon $occurredAt,
        ?string $newValue = null,
    ): bool {
        return ProcurementEvent::query()
            ->where('procurement_id', $procurementId)
            ->where('event_type', $eventType)
            ->where('occurred_at', $occurredAt)
            ->where('new_value', $newValue)
            ->exists();
    }

    public function hasDocumentVersionEvent(int $procurementId, int $documentVersionId): bool
    {
        return ProcurementEvent::query()
            ->where('procurement_id', $procurementId)
            ->whereJsonContains('entity_ref', ['document_version_id' => $documentVersionId])
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $entityRef
     * @param  array<string, mixed>|null  $payload
     */
    public function storeEvent(
        int $procurementId,
        ProcurementEventType $eventType,
        Carbon $occurredAt,
        ?int $revisionId = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?array $entityRef = null,
        ?array $payload = null,
    ): ProcurementEvent {
        return ProcurementEvent::query()->create([
            'procurement_id' => $procurementId,
            'revision_id' => $revisionId,
            'event_type' => $eventType,
            'entity_ref' => $entityRef,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);
    }
}
