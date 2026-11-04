<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\MetricTotalRow;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProjectedEvent;
use App\Dto\Procurement\Process\ProjectionState;
use App\Dto\Procurement\Process\ProjectionTotals;
use App\Enums\Procurement\Process\ProjectionMode;
use Illuminate\Support\Facades\DB;

/**
 * Единственный writer `prc_process_projection_states/receipts/metric_totals`.
 * Порядок блокировок задаёт вызывающий: state FOR SHARE (live apply) либо FOR UPDATE (rebuild,
 * retention, resume) берётся ДО receipt и aggregate rows, без SHARE→UPDATE upgrade. Mutation
 * методы требуют транзакцию вызывающего; сети и object-store здесь нет.
 */
final readonly class ProjectionRepository
{
    private const string EVENT_COLUMNS = "t.event_id::text AS event_id, t.entity_kind, t.entity_seq, t.previous_state, t.new_state,
        t.reason_code, t.schema_version, w.stage,
        CASE WHEN p.code IS NOT NULL AND w.cohort_key = ('platform:' || w.platform_id::text) THEN 'platform:' || p.code ELSE w.cohort_key END AS cohort_key";

    private const string EVENT_FROM = 'FROM prc_process_transitions t LEFT JOIN prc_process_work w ON w.id = t.work_id LEFT JOIN prc_platforms p ON p.id = w.platform_id';

    private const string NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /** Seed state перед первым запуском consumer: generation 1 active; существующий state не трогается. */
    public function seed(string $projectorKey, ContentHash $catalogHash): void
    {
        DB::insert(
            "INSERT INTO prc_process_projection_states (projector_key, mode, active_generation, catalog_hash)
             VALUES (?, 'active', 1, decode(?, 'hex')) ON CONFLICT (projector_key) DO NOTHING",
            [$projectorKey, $catalogHash->hex],
        );
    }

    public function state(string $projectorKey): ?ProjectionState
    {
        return $this->fetchState($projectorKey, '');
    }

    /** Live apply: SHARE сериализует его с rebuild gate (FOR UPDATE) и не конфликтует с другими apply. */
    public function stateShared(string $projectorKey): ?ProjectionState
    {
        CallerTransaction::require('Projection state share lock');

        return $this->fetchState($projectorKey, 'FOR SHARE');
    }

    public function stateForUpdate(string $projectorKey): ?ProjectionState
    {
        CallerTransaction::require('Projection state update lock');

        return $this->fetchState($projectorKey, 'FOR UPDATE');
    }

    public function event(ProcessUuid $eventId): ?ProjectedEvent
    {
        $row = LedgerRow::one('SELECT '.self::EVENT_COLUMNS.' '.self::EVENT_FROM.' WHERE t.event_id = ?', [$eventId->value]);

        return $row === null ? null : self::projected($row);
    }

    /** entity_kind события без блокировок: kind неизменяем, нужен только для выбора получателей. */
    public function entityKind(ProcessUuid $eventId): ?string
    {
        return LedgerRow::one('SELECT entity_kind FROM prc_process_transitions WHERE event_id = ?', [$eventId->value])?->string('entity_kind');
    }

    /**
     * @param  list<ProcessUuid>  $eventIds
     * @return array<string, string> event_id => entity_kind существующих событий
     */
    public function entityKinds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $kinds = [];
        foreach (LedgerRow::all(
            'SELECT event_id::text AS event_id, entity_kind FROM prc_process_transitions WHERE event_id = ANY(?::uuid[])',
            [self::uuidArray($eventIds)],
        ) as $row) {
            $kinds[$row->string('event_id')] = $row->string('entity_kind');
        }

        return $kinds;
    }

    /** Глобальный dedup `(projector_key, event_id)`: false — receipt уже есть, событие не применять. */
    public function insertReceipt(string $projectorKey, ProcessUuid $eventId): bool
    {
        CallerTransaction::require('Projection receipt insert');

        return DB::affectingStatement(
            'INSERT INTO prc_process_projection_receipts (projector_key, event_id, applied_at)
             VALUES (?, ?, clock_timestamp()) ON CONFLICT (projector_key, event_id) DO NOTHING',
            [$projectorKey, $eventId->value],
        ) === 1;
    }

    /**
     * Rebuild выдаёт receipts страницы одним statement; возвращает число НОВЫХ (ранее unapplied) событий.
     *
     * @param  list<ProcessUuid>  $eventIds
     */
    public function insertReceipts(string $projectorKey, array $eventIds): int
    {
        CallerTransaction::require('Projection receipts insert');
        if ($eventIds === []) {
            return 0;
        }

        return DB::affectingStatement(
            'INSERT INTO prc_process_projection_receipts (projector_key, event_id, applied_at)
             SELECT ?::text, e, clock_timestamp() FROM unnest(?::uuid[]) AS e WHERE true
             ON CONFLICT (projector_key, event_id) DO NOTHING',
            [$projectorKey, self::uuidArray($eventIds)],
        );
    }

    /** Атомарный UPSERT приращения; вместе с receipt в одной tx, поэтому crash между ними откатывает оба. */
    public function increment(string $projectorKey, int $generation, string $metricKey, string $dimensionKey, int $amount): void
    {
        CallerTransaction::require('Projection counter upsert');
        DB::insert(
            'INSERT INTO prc_process_metric_totals (projector_key, generation, metric_key, dimension_key, value, updated_at)
             VALUES (?, ?, ?, ?, ?, clock_timestamp())
             ON CONFLICT (projector_key, generation, metric_key, dimension_key)
             DO UPDATE SET value = prc_process_metric_totals.value + EXCLUDED.value, updated_at = clock_timestamp()',
            [$projectorKey, $generation, $metricKey, $dimensionKey, $amount],
        );
    }

    /**
     * Active totals одним SQL-снимком с state.active_generation, не двумя несогласованными запросами.
     *
     * @return list<MetricTotalRow>
     */
    public function activeTotals(string $projectorKey): array
    {
        return $this->activeSnapshot($projectorKey)->totals;
    }

    /** Active generation, mode and counters must agree even if a rebuild flips between reads. */
    public function activeSnapshot(string $projectorKey): ProjectionTotals
    {
        $rows = LedgerRow::all("SELECT s.projector_key, s.mode, s.active_generation,
                encode(s.catalog_hash, 'hex') AS catalog_hash, s.history_complete,
                s.maintenance_token::text AS maintenance_token, t.metric_key, t.dimension_key, t.value
            FROM prc_process_projection_states s
            LEFT JOIN prc_process_metric_totals t ON t.projector_key=s.projector_key AND t.generation=s.active_generation
            WHERE s.projector_key=? ORDER BY t.metric_key, t.dimension_key", [$projectorKey]);
        $totals = [];
        foreach ($rows as $row) {
            if ($row->nullableString('metric_key') !== null) {
                $totals[] = new MetricTotalRow($row->string('metric_key'), $row->string('dimension_key'), $row->int('value'));
            }
        }

        return new ProjectionTotals($rows === [] ? null : self::projectionState($rows[0]), $totals);
    }

    /** @return list<MetricTotalRow> */
    public function generationTotals(string $projectorKey, int $generation): array
    {
        return array_map(
            static fn (LedgerRow $row): MetricTotalRow => new MetricTotalRow($row->string('metric_key'), $row->string('dimension_key'), $row->int('value')),
            LedgerRow::all(
                'SELECT metric_key, dimension_key, value FROM prc_process_metric_totals
                 WHERE projector_key = ? AND generation = ? ORDER BY metric_key, dimension_key',
                [$projectorKey, $generation],
            ),
        );
    }

    public function insertTotal(string $projectorKey, int $generation, MetricTotalRow $total): void
    {
        CallerTransaction::require('Projection total insert');
        DB::insert(
            'INSERT INTO prc_process_metric_totals (projector_key, generation, metric_key, dimension_key, value, updated_at)
             VALUES (?, ?, ?, ?, ?, clock_timestamp())',
            [$projectorKey, $generation, $total->metricKey, $total->dimensionKey, $total->value],
        );
    }

    /**
     * Ограниченный счёт: не больше $limit строк, чтобы проверка размера истории сама была bounded.
     *
     * @param  list<string>  $kinds
     */
    public function countEvents(array $kinds, int $limit): int
    {
        return (LedgerRow::one(
            'SELECT count(*) AS n FROM (SELECT 1 FROM prc_process_transitions WHERE entity_kind IN ('.LedgerRow::placeholders(count($kinds)).') LIMIT ?) s',
            [...$kinds, $limit],
        ) ?? throw new \LogicException('Aggregate query always returns a row.'))->int('n');
    }

    /**
     * Страница событий kinds после курсора по event_id (стабильный порядок в одном MVCC snapshot).
     *
     * @param  list<string>  $kinds
     * @return list<ProjectedEvent>
     */
    public function eventPage(array $kinds, ?ProcessUuid $after, int $limit): array
    {
        return array_map(self::projected(...), LedgerRow::all(
            'SELECT '.self::EVENT_COLUMNS.' '.self::EVENT_FROM.'
             WHERE t.entity_kind IN ('.LedgerRow::placeholders(count($kinds)).') AND t.event_id > ?::uuid
             ORDER BY t.event_id LIMIT ?',
            [...$kinds, $after === null ? self::NIL_UUID : $after->value, $limit],
        ));
    }

    /**
     * Receipts проектора по событиям kinds: должно совпасть с числом обработанных rebuild событий.
     *
     * @param  list<string>  $kinds
     */
    public function receiptCount(string $projectorKey, array $kinds): int
    {
        return (LedgerRow::one(
            'SELECT count(*) AS n FROM prc_process_projection_receipts r JOIN prc_process_transitions t ON t.event_id = r.event_id
             WHERE r.projector_key = ? AND t.entity_kind IN ('.LedgerRow::placeholders(count($kinds)).')',
            [$projectorKey, ...$kinds],
        ) ?? throw new \LogicException('Aggregate query always returns a row.'))->int('n');
    }

    /** Переводит active → maintenance под уже взятым FOR UPDATE; false — gate занят. */
    public function enterMaintenance(string $projectorKey, ProcessUuid $token): bool
    {
        CallerTransaction::require('Projection maintenance gate');

        return DB::affectingStatement(
            "UPDATE prc_process_projection_states
             SET mode = 'maintenance', maintenance_token = ?, maintenance_started_at = clock_timestamp()
             WHERE projector_key = ? AND mode = 'active'",
            [$token->value, $projectorKey],
        ) === 1;
    }

    /** Снимает gate только владельцу token; чужой или устаревший token ничего не меняет. */
    public function releaseMaintenance(string $projectorKey, ProcessUuid $token): bool
    {
        CallerTransaction::require('Projection maintenance release');

        return DB::affectingStatement(
            "UPDATE prc_process_projection_states
             SET mode = 'active', maintenance_token = NULL, maintenance_started_at = NULL
             WHERE projector_key = ? AND maintenance_token = ?",
            [$projectorKey, $token->value],
        ) === 1;
    }

    /** CAS публикации новой generation: token + прежняя generation; reader видит целиком old либо new. */
    public function publishGeneration(string $projectorKey, ProcessUuid $token, int $previous, int $next): bool
    {
        CallerTransaction::require('Projection generation publish');

        return DB::affectingStatement(
            "UPDATE prc_process_projection_states
             SET active_generation = ?, mode = 'active', maintenance_token = NULL, maintenance_started_at = NULL,
                 rebuilt_at = clock_timestamp()
             WHERE projector_key = ? AND maintenance_token = ? AND active_generation = ?",
            [$next, $projectorKey, $token->value, $previous],
        ) === 1;
    }

    /** Totals неактивных generation удаляются только под state lock, active_generation не затрагивается. */
    public function deleteInactiveTotals(string $projectorKey): int
    {
        CallerTransaction::require('Projection inactive totals cleanup');

        return DB::affectingStatement(
            'DELETE FROM prc_process_metric_totals t USING prc_process_projection_states s
             WHERE s.projector_key = t.projector_key AND t.projector_key = ? AND t.generation <> s.active_generation',
            [$projectorKey],
        );
    }

    /**
     * Необратимо помечает историю projector неполной (первое значение pruned_at сохраняется); вызывается
     * retention writer-ом под FOR UPDATE этих state rows, в одной tx с удалением событий.
     *
     * @param  list<string>  $projectorKeys
     */
    public function markHistoryPruned(array $projectorKeys): void
    {
        CallerTransaction::require('Projection history pruned mark');
        if ($projectorKeys === []) {
            return;
        }
        DB::update(
            'UPDATE prc_process_projection_states
             SET history_complete = false, history_pruned_at = COALESCE(history_pruned_at, clock_timestamp())
             WHERE projector_key IN ('.LedgerRow::placeholders(count($projectorKeys)).')',
            $projectorKeys,
        );
    }

    /**
     * @param  list<string>  $projectorKeys
     * @return list<string> ключи, у которых receipt события уже есть
     */
    public function receiptKeys(ProcessUuid $eventId, array $projectorKeys): array
    {
        return array_map(
            static fn (LedgerRow $row): string => $row->string('projector_key'),
            LedgerRow::all(
                'SELECT projector_key FROM prc_process_projection_receipts WHERE event_id = ? AND projector_key IN ('.LedgerRow::placeholders(count($projectorKeys)).')',
                [$eventId->value, ...$projectorKeys],
            ),
        );
    }

    /** Unpublished projection fanout события: retention не удаляет source, пока consumer не подтверждён. */
    public function fanoutPending(ProcessUuid $eventId): bool
    {
        return LedgerRow::one(
            "SELECT 1 AS pending FROM prc_process_outbox WHERE dedup_key = ? AND kind = 'projection' AND published_at IS NULL",
            ['project:'.$eventId->value],
        ) !== null;
    }

    /** Удаление истории: receipts (RESTRICT FK) затем событие; только из retention writer. */
    public function deleteEvent(ProcessUuid $eventId): void
    {
        CallerTransaction::require('Projection history delete');
        DB::delete('DELETE FROM prc_process_projection_receipts WHERE event_id = ?', [$eventId->value]);
        DB::delete('DELETE FROM prc_process_transitions WHERE event_id = ?', [$eventId->value]);
    }

    private function fetchState(string $projectorKey, string $lock): ?ProjectionState
    {
        $row = LedgerRow::one(
            "SELECT projector_key, mode, active_generation, encode(catalog_hash, 'hex') AS catalog_hash, history_complete,
                    maintenance_token::text AS maintenance_token
             FROM prc_process_projection_states WHERE projector_key = ? {$lock}",
            [$projectorKey],
        );
        if ($row === null) {
            return null;
        }

        return self::projectionState($row);
    }

    private static function projectionState(LedgerRow $row): ProjectionState
    {
        $token = $row->nullableString('maintenance_token');

        return new ProjectionState(
            projectorKey: $row->string('projector_key'),
            mode: ProjectionMode::from($row->string('mode')),
            activeGeneration: $row->int('active_generation'),
            catalogHash: new ContentHash($row->string('catalog_hash')),
            historyComplete: $row->bool('history_complete'),
            maintenanceToken: $token === null ? null : new ProcessUuid($token),
        );
    }

    private static function projected(LedgerRow $row): ProjectedEvent
    {
        return new ProjectedEvent(
            eventId: new ProcessUuid($row->string('event_id')),
            entityKind: $row->string('entity_kind'),
            entitySeq: $row->int('entity_seq'),
            previousState: $row->nullableString('previous_state'),
            newState: $row->string('new_state'),
            reasonCode: $row->nullableString('reason_code'),
            schemaVersion: $row->int('schema_version'),
            stage: $row->nullableString('stage'),
            cohortKey: $row->nullableString('cohort_key'),
        );
    }

    /** @param list<ProcessUuid> $ids */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', array_map(static fn (ProcessUuid $id): string => $id->value, $ids)).'}';
    }
}
