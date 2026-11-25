<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Acquisition\RefreshPolicy;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use Illuminate\Support\Facades\DB;

/**
 * Последовательный lifecycle acquisition intents одного (stage, subject).
 * Partial unique index допускает один active; остальные ждут в pending. Когда active intent
 * завершается (успех, failed_item, cancel), следующий pending без pending_reason активируется
 * с expected_head_generation текущего subject head. Вызывается только под уже взятым subject
 * lock (prefix или publication locks) в caller transaction.
 */
final readonly class IntentRepository
{
    /** Caller holds the subject lock; replace staging intents before publishing active work. */
    public function retirePendingLocked(StageCode $stage, SubjectKey $subject): void
    {
        CallerTransaction::require('Pending intent retirement');
        DB::update(
            "UPDATE prc_operation_intents SET state='terminal',pending_reason=NULL
             WHERE stage=? AND subject_kind=? AND subject_id=? AND state='pending'",
            [
                $stage->value,
                $subject->kind->value,
                is_int($subject->id) ? (string) $subject->id : $subject->id->value,
            ],
        );
    }

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
        $stage = $intent->string('stage');
        $subjectKind = $intent->string('subject_kind');
        $subjectId = $intent->string('subject_id');
        if ($intent->string('state') !== IntentState::Terminal->value) {
            // Pending intent, чья work завершилась до активации, выходит из очереди и не активируется пустым.
            DB::update(
                "UPDATE prc_operation_intents
                 SET state = 'terminal',
                     next_due_at = CASE WHEN stage = 'detail' AND state = 'active' AND next_due_at IS NULL
                         AND EXISTS (
                             SELECT 1 FROM prc_operation_intents s JOIN prc_process_work sw ON sw.intent_id = s.id
                             WHERE s.subject_kind = 'prc_procurements' AND s.subject_id = prc_operation_intents.subject_id
                               AND s.stage = 'detail' AND s.state = 'terminal' AND sw.state = 'succeeded'
                         )
                         THEN clock_timestamp() + (? * interval '1 second') ELSE next_due_at END
                 WHERE id = ? AND state IN ('active', 'pending')",
                [(new RefreshPolicy)->detailSeconds, $intentId],
            );
        }
        if ($intent->string('state') !== IntentState::Pending->value) {
            $activated = $this->activateNextLocked($stage, $subjectKind, $subjectId);
            if ($terminate && $activated === null && $subjectKind === SubjectKind::Document->value) {
                DB::update(
                    "UPDATE prc_documents
                     SET next_revalidation_due_at = COALESCE(next_revalidation_due_at, clock_timestamp() + interval '1 day')
                     WHERE id = ? AND ? = 'download' AND current_version_id IS NOT NULL AND removed_at IS NULL",
                    [(int) $subjectId, $stage],
                );
            }
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
               AND EXISTS (SELECT 1 FROM prc_process_work w WHERE w.intent_id = prc_operation_intents.id)
             ORDER BY requested_at, id LIMIT 1 FOR UPDATE",
            [$stage, $subjectKind, $subjectId],
        );
        if ($next === null) {
            return null;
        }
        $kind = SubjectKind::from($subjectKind);
        $table = $kind->value;
        $headColumn = AuthorityRepository::headColumn($kind);
        $idType = match ($kind) {
            SubjectKind::Procurement, SubjectKind::Document, SubjectKind::DocumentVersion => 'bigint',
            SubjectKind::CrawlBlock, SubjectKind::AnalysisRun, SubjectKind::AnalysisWindow => 'uuid',
        };
        $head = LedgerRow::one("SELECT {$headColumn} FROM {$table} WHERE id = ?::{$idType}", [$subjectId])
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
