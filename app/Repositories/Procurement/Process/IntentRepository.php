<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\SubjectKind;
use Illuminate\Support\Facades\DB;

/**
 * Последовательный lifecycle acquisition intents одного (stage, subject) (execution#s10).
 * Partial unique index допускает один active; остальные ждут в pending. Когда active intent
 * завершается (успех, failed_item, cancel), следующий pending без pending_reason активируется
 * с expected_head_generation текущего subject head. Вызывается только под уже взятым subject
 * lock (prefix или publication locks) в caller transaction.
 */
final readonly class IntentRepository
{
    /**
     * Завершить intent work, ушедшей в terminal без successor (failed_item, cancel), и активировать
     * следующий pending того же subject/stage. С $terminate=false (успех: stage publication уже
     * перевела intent в terminal сама) active intent не трогается, только продвигается очередь.
     */
    public function releaseLocked(string $intentId, bool $terminate = true): void
    {
        CallerTransaction::require('Intent release');
        $intent = LedgerRow::one(
            'SELECT stage, subject_kind, subject_id, state FROM prc_operation_intents WHERE id = ? FOR UPDATE',
            [$intentId],
        );
        if ($intent === null || (! $terminate && $intent->string('state') !== IntentState::Terminal->value)) {
            return;
        }
        if ($intent->string('state') !== IntentState::Terminal->value) {
            // Pending intent, чья work завершилась до активации, выходит из очереди и не активируется пустым.
            DB::update("UPDATE prc_operation_intents SET state = 'terminal' WHERE id = ? AND state IN ('active', 'pending')", [$intentId]);
        }
        if ($intent->string('state') !== IntentState::Pending->value) {
            $this->activateNextLocked($intent->string('stage'), $intent->string('subject_kind'), $intent->string('subject_id'));
        }
    }

    /**
     * Активирует старейший pending intent без pending_reason, если active нет. expected head
     * пересчитывается по текущему subject: прежний pin устарел, пока intent ждал очереди.
     */
    public function activateNextLocked(string $stage, string $subjectKind, string $subjectId): ?string
    {
        CallerTransaction::require('Intent activation');
        $active = LedgerRow::one(
            "SELECT id FROM prc_operation_intents WHERE stage = ? AND subject_kind = ? AND subject_id = ? AND state = 'active'",
            [$stage, $subjectKind, $subjectId],
        );
        if ($active !== null) {
            return null;
        }
        $next = LedgerRow::one(
            "SELECT id FROM prc_operation_intents
             WHERE stage = ? AND subject_kind = ? AND subject_id = ? AND state = 'pending' AND pending_reason IS NULL
             ORDER BY requested_at, id LIMIT 1 FOR UPDATE",
            [$stage, $subjectKind, $subjectId],
        );
        if ($next === null) {
            return null;
        }
        $table = SubjectKind::from($subjectKind)->value;
        $head = LedgerRow::one("SELECT head_generation FROM {$table} WHERE id::text = ?", [$subjectId])
            ?? throw new \LogicException('Pending intent subject disappeared.');
        DB::update(
            "UPDATE prc_operation_intents SET state = 'active', expected_head_generation = ? WHERE id = ? AND state = 'pending'",
            [$head->int('head_generation'), $next->string('id')],
        );

        return $next->string('id');
    }

    /** Новый intent этого subject/stage: active, если очередь пуста, иначе pending. */
    public function admissionState(string $stage, string $subjectKind, string $subjectId): IntentState
    {
        return LedgerRow::one(
            "SELECT 1 AS present FROM prc_operation_intents WHERE stage = ? AND subject_kind = ? AND subject_id = ? AND state = 'active'",
            [$stage, $subjectKind, $subjectId],
        ) === null ? IntentState::Active : IntentState::Pending;
    }
}
