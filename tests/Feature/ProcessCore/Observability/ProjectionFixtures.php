<?php

declare(strict_types=1);

namespace Tests\Feature\ProcessCore\Observability;

use App\Dto\Procurement\Process\Governance\TransitionData;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Governance\ActorKind;
use App\Repositories\Procurement\Process\Projection\TransitionRepository;
use App\Services\Procurement\Process\Projection\MetricCatalog;
use App\Services\Procurement\Process\Projection\MetricProjector;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

/**
 * Реальные ledger rows для projection tests: run/work создают настоящие writers с
 * transition + projection outbox; дополнительные work transitions пишет TransitionRepository
 * с монотонным entity_seq. Прямые INSERT — только для DB-negative событий вне каталога.
 */
final class ProjectionFixtures
{
    /** @return array{run: ProcessUuid, block: ProcessUuid, work: WorkId} */
    public static function workInRun(string $cohort = 'manual'): array
    {
        $block = LedgerFixtures::crawlBlock();
        $work = LedgerFixtures::storeWork(
            LedgerFixtures::listingInput($block['block'], $block['filter_hash'], $block['access_policy_hash']),
            originRunId: $block['run'],
        );
        if ($cohort !== 'manual') {
            DB::table('prc_process_work')->where('id', $work->value->value)->update(['cohort_key' => $cohort]);
        }

        return ['run' => $block['run'], 'block' => $block['block'], 'work' => $work];
    }

    /** Следующий work transition в журнале; entity_seq продолжает последовательность этой work. */
    public static function transition(WorkId $work, string $new, ?string $reason = null, ?string $previous = null): ProcessUuid
    {
        $seq = (int) DB::table('prc_process_transitions')->where('work_id', $work->value->value)->max('entity_seq') + 1;
        $event = LedgerFixtures::uuid();
        app(TransitionRepository::class)->recordWork(new TransitionData(
            eventId: $event,
            projectionMessageId: LedgerFixtures::uuid(),
            entityId: $work->value,
            entitySeq: $seq,
            previousState: $previous,
            newState: $new,
            reasonCode: $reason,
            actor: ActorKind::System,
            generation: 1,
        ));

        return $event;
    }

    /** Сырой work transition вне каталога (DB-negative): неизвестное состояние либо неподдерживаемая schema. */
    public static function rawWorkEvent(WorkId $work, string $newState, int $schemaVersion = 1): ProcessUuid
    {
        $seq = (int) DB::table('prc_process_transitions')->where('work_id', $work->value->value)->max('entity_seq') + 1;
        $event = LedgerFixtures::uuid();
        DB::table('prc_process_transitions')->insert([
            'event_id' => $event->value, 'entity_kind' => 'work', 'work_id' => $work->value->value, 'entity_seq' => $seq,
            'new_state' => $newState, 'actor_kind' => 'system', 'schema_version' => $schemaVersion,
        ]);

        return $event;
    }

    /** @return list<ProcessUuid> */
    public static function eventIds(): array
    {
        return array_map(
            static fn (string $id): ProcessUuid => new ProcessUuid($id),
            DB::table('prc_process_transitions')->orderBy('event_id')->pluck('event_id')->all(),
        );
    }

    public static function applyAll(): void
    {
        foreach (self::eventIds() as $eventId) {
            app(MetricProjector::class)->apply($eventId);
        }
    }

    public static function workDimension(string $stage, string $state, string $platform = 'manual'): string
    {
        return MetricCatalog::dimensionKey('work_transition_total', ['platform' => $platform, 'stage' => $stage, 'state' => $state]);
    }

    /** Значение серии ACTIVE generation (как читает UI): join со state одним запросом. */
    public static function total(string $projector, string $metric, string $dimension): int
    {
        return (int) DB::table('prc_process_metric_totals as t')
            ->join('prc_process_projection_states as s', static function (JoinClause $join): void {
                $join->on('s.projector_key', '=', 't.projector_key')->on('s.active_generation', '=', 't.generation');
            })
            ->where('t.projector_key', $projector)->where('t.metric_key', $metric)->where('t.dimension_key', $dimension)
            ->value('t.value');
    }

    public static function receipts(string $projector): int
    {
        return DB::table('prc_process_projection_receipts')->where('projector_key', $projector)->count();
    }

    public static function totalRows(string $projector): int
    {
        return DB::table('prc_process_metric_totals')->where('projector_key', $projector)->count();
    }

    public static function generation(string $projector): int
    {
        return (int) DB::table('prc_process_projection_states')->where('projector_key', $projector)->value('active_generation');
    }
}
