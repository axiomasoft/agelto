<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Projection\ProjectionApplyStatus;
use App\Enums\Procurement\Process\Projection\ProjectionMode;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;
use App\Repositories\Procurement\Process\Projection\ProjectionRepository;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Live apply ledger events registered reducers. Один reducer — одна короткая
 * READ COMMITTED tx: state FOR SHARE (до receipt и aggregate rows, без upgrade) → immutable source
 * event → INSERT receipt → UPSERT counters. Receipt и counters коммитятся вместе, поэтому дубликат
 * или повтор после crash ровно +1; порядок доставки значения не имеет, потому что скаляр last-seq
 * не используется. Maintenance не применяет и не подтверждает: событие остаётся unapplied.
 */
final readonly class MetricProjector
{
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private ProjectionRegistry $registry,
        private ProjectionRepository $projections,
        private ProcessTransaction $transaction,
    ) {}

    /** State каждого зарегистрированного consumer существует (generation 1, active) до его первого запуска. */
    public function ensureStates(): void
    {
        foreach ($this->registry->all() as $reducer) {
            $this->projections->seed($reducer->key(), $this->registry->catalogHash($reducer));
        }
    }

    /**
     * Применяет событие всем получателям его kind. Неизвестное событие (source удалён retention) даёт
     * SourceUnavailable для каждого получателя: нулевая дельта, факт не воссоздаётся из payload.
     *
     * @return array<string, ProjectionApplyStatus> projector_key => исход
     */
    public function apply(ProcessUuid $eventId): array
    {
        $kind = $this->projections->entityKind($eventId);
        $results = [];
        if ($kind === null) {
            foreach ($this->registry->all() as $reducer) {
                $results[$reducer->key()] = ProjectionApplyStatus::SourceUnavailable;
            }

            return $results;
        }
        foreach ($this->registry->forEntityKind($kind) as $reducer) {
            $results[$reducer->key()] = $this->applyOne($reducer, $eventId);
        }

        return $results;
    }

    public function applyOne(ProjectionReducer $reducer, ProcessUuid $eventId): ProjectionApplyStatus
    {
        $key = $reducer->key();
        // Seed вне tx apply (state обычно уже есть): ON CONFLICT DO NOTHING идемпотентен, под SHARE state не пропадает.
        if ($this->projections->state($key) === null) {
            $this->projections->seed($key, $this->registry->catalogHash($reducer));
        }

        try {
            return $this->transaction->run(fn (): ProjectionApplyStatus => $this->applyLocked($reducer, $key, $eventId));
        } catch (QueryException $failure) {
            // Rebuild держит state FOR UPDATE на всю свою bounded tx: короткий lock_timeout (см. applyLocked)
            // означает «gate занят», а не сбой projector — событие остаётся unapplied и придёт позже.
            if (($failure->errorInfo[0] ?? null) === self::LOCK_NOT_AVAILABLE) {
                return ProjectionApplyStatus::Maintenance;
            }
            throw $failure;
        }
    }

    private function applyLocked(ProjectionReducer $reducer, string $key, ProcessUuid $eventId): ProjectionApplyStatus
    {
        // Переопределяет общий lock_timeout 2 s ProcessTransaction только для этой tx.
        DB::statement("SET LOCAL lock_timeout = '250ms'");
        $state = $this->projections->stateShared($key) ?? throw new \LogicException('Projection state vanished after seeding: '.$key);
        if ($state->mode !== ProjectionMode::Active) {
            return ProjectionApplyStatus::Maintenance;
        }
        if ($state->catalogHash->hex !== $this->registry->catalogHash($reducer)->hex) {
            return ProjectionApplyStatus::CatalogMismatch;
        }
        try {
            $event = $this->projections->event($eventId);
        } catch (\InvalidArgumentException) {
            // Факт ledger не выражается типизированным событием: изолируем одно событие (quarantine +
            // incident в daemon), а не роняем batch, иначе самое раннее malformed блокирует все остальные.
            return ProjectionApplyStatus::SchemaInvalid;
        }
        if ($event === null) {
            return ProjectionApplyStatus::SourceUnavailable;
        }
        if ($event->schemaVersion !== 1) {
            return ProjectionApplyStatus::SchemaUnsupported;
        }
        if (! in_array($event->entityKind, $reducer->entityKinds(), true)) {
            return ProjectionApplyStatus::SchemaInvalid;
        }
        try {
            $deltas = $reducer->reduce($event);
        } catch (ProjectionSchemaError) {
            return ProjectionApplyStatus::SchemaInvalid;
        }
        if (! $this->projections->insertReceipt($key, $eventId)) {
            return ProjectionApplyStatus::Duplicate;
        }
        foreach ($deltas as $delta) {
            $this->projections->increment($key, $state->activeGeneration, $delta->metricKey, $delta->dimensionKey, $delta->amount);
        }

        return ProjectionApplyStatus::Applied;
    }
}
