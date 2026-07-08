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
    case Ignored = 'ignored';

    /**
     * Скрининг документа доведён до ветвления (P5.2, ТЗ 03 §3.4/D36): `entityRef` содержит
     * `document_id`+`analysis_id`, `newValue` — исход (`estimate`/`screened`/`ignored`/
     * `needs_review`), `payload` — `document_type`/`confidence`/причина.
     */
    case DocumentScreened = 'document_screened';
}
