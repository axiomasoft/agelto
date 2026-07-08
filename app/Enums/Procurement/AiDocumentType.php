<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Тип документа, определённый LLM-скринингом (шаг screening, docs/specs/v2/03-document-pipeline.md §3.4).
 * - Estimate: смета (ЛСР, КС-2, ведомость ресурсов, дефектная ведомость).
 * - TechSpec: техническое задание.
 * - ContractDraft: проект контракта/договора.
 * - Protocol: протокол (рассмотрения заявок, подведения итогов и т. п.).
 * - Unknown: тип не распознан.
 */
enum AiDocumentType: string
{
    case Estimate = 'estimate';
    case TechSpec = 'tech_spec';
    case ContractDraft = 'contract_draft';
    case Protocol = 'protocol';
    case Unknown = 'unknown';
}
