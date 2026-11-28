<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Parsing;

/**
 * Тип события в неизменяемом журнале правил парсинга (`prc_parsing_rule_events`).
 */
enum ParsingRuleEventType: string
{
    case Created = 'created';
    case Validated = 'validated';
    case ValidationFailed = 'validation_failed';
    case Activated = 'activated';
    case RolledBack = 'rolled_back';
    case Archived = 'archived';
    case DriftDetected = 'drift_detected';
    case CandidateProposed = 'candidate_proposed';
    case CandidateVerified = 'candidate_verified';
}
