<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

enum ScreeningDecisionReason: string
{
    case IgnoreRule = 'ignore_rule';
    case LowConfidence = 'low_confidence';
    case BorderlineConfidence = 'borderline_confidence';
    case UnknownType = 'unknown_type';
    case KnownTypeNoAnalyzer = 'known_type_no_analyzer';
    case AnalyzerAvailable = 'analyzer_available';
}
