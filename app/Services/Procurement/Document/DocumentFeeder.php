<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;

/** Bounded independent producer; admission, fairness, delivery and recovery have one Process owner. */
final readonly class DocumentFeeder implements ReconcileCategory
{
    public function __construct(private DocumentWorkRepository $works, private DocumentRevalidator $revalidator, private PreparePolicy $prepare) {}

    public function name(): string
    {
        return 'document_intents';
    }

    public function reconcile(): int
    {
        $created = 0;
        foreach (LedgerRow::all("SELECT id FROM prc_documents WHERE source_document_key IS NOT NULL AND removed_at IS NULL
            AND current_version_id IS NOT NULL AND next_revalidation_due_at<=clock_timestamp()
            AND NOT EXISTS (SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_documents'
                AND i.subject_id=prc_documents.id::text AND i.stage='download' AND i.state IN ('active','pending'))
            ORDER BY next_revalidation_due_at,id LIMIT 50") as $row) {
            $created += (int) ($this->revalidator->allocate($row->int('id')) !== null);
        }
        foreach (LedgerRow::all("SELECT d.id FROM prc_documents d WHERE d.removed_at IS NULL AND EXISTS
            (SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_documents' AND i.subject_id=d.id::text
            AND i.stage='download' AND i.state='pending') AND NOT EXISTS
            (SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_documents' AND i.subject_id=d.id::text
            AND i.stage='download' AND i.state='active') ORDER BY d.id LIMIT 50") as $row) {
            $created += $this->works->activate($row->int('id'));
        }
        // Missing work only: a blocked/failed item keeps its history and cannot be reset by polling.
        foreach (LedgerRow::all("SELECT d.id FROM prc_documents d WHERE d.removed_at IS NULL AND d.current_version_id IS NOT NULL AND NOT EXISTS
            (SELECT 1 FROM prc_process_work w WHERE w.stage='prepare' AND w.document_version_id=d.current_version_id
            AND w.input_payload->>'preparation_recipe_hash'=?) ORDER BY d.id LIMIT 50", [$this->prepare->hash->hex]) as $row) {
            $created += $this->works->prepare($row->int('id'));
        }

        return $created;
    }
}
