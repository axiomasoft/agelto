<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Тип события в журнале закупки (`prc_events`).
 */
enum ProcurementEventType: string
{
    case Discovered = 'discovered';
    case RevisionPublished = 'revision_published';
    case StatusChanged = 'status_changed';
    case PriceChanged = 'price_changed';
    case DatesChanged = 'dates_changed';
    case DocumentAdded = 'document_added';
    case DocumentReplaced = 'document_replaced';
    case DocumentRemoved = 'document_removed';
    case LotChanged = 'lot_changed';
    case ProtocolPublished = 'protocol_published';
    case ClarificationPublished = 'clarification_published';
    case Cancelled = 'cancelled';
    case Restored = 'restored';
    case AwardMade = 'award_made';
    /**
     * Закупка впервые достигла положительного исхода; витрина истории, не гарантия единственности.
     */
    case Succeeded = 'succeeded';
    case Ignored = 'ignored';

    /**
     * Скрининг документа доведён до typed backend-решения: `entityRef` содержит
     * `document_id`+`analysis_id`, `newValue` — тип для `extract` либо disposition,
     * `payload` — `document_type`/`confidence`/`disposition`/`reason`.
     */
    case DocumentScreened = 'document_screened';

    /**
     * Смета записана в `prc_estimate_*`: `entityRef` содержит document_id и estimate_id,
     * `newValue` — validation_status, `payload` — ordinal и rows_extracted.
     */
    case EstimateExtracted = 'estimate_extracted';
}
