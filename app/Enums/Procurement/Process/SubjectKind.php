<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum SubjectKind: string
{
    case CrawlBlock = 'prc_crawl_blocks';
    case Procurement = 'prc_procurements';
    case Document = 'prc_documents';
    case DocumentVersion = 'prc_document_versions';
    case AnalysisRun = 'prc_analysis_runs';
    case AnalysisWindow = 'prc_analysis_windows';
}
