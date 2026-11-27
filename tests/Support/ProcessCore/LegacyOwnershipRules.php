<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Tests\Support\Architecture\PlatformSourceRules;

/** Checks executable symbols and reader/table literals; comments and test fixtures are excluded. */
final class LegacyOwnershipRules
{
    public const array TABLES = ['prc_crawler_runs', 'prc_source_circuits', 'prc_source_incidents', 'prc_proxy_targets', 'prc_proxy_checks', 'prc_crawler_proxy', 'prc_operation_logs', 'prc_fetches'];

    public const array SYMBOLS = [
        'App\Services\Procurement\CrawlerRunManager',
        'App\Services\Procurement\ArchiveWindowScanner',
        'App\Services\Procurement\WindowStability',
        'App\Services\Procurement\CsvBlockIngestor',
        'App\Services\Procurement\ListingRunCheckpoint',
        'App\Services\Procurement\RunStopGate',
        'App\Services\Procurement\DetailDispatcher',
        'App\Services\Procurement\DetailRefetchSelector',
        'App\Services\Procurement\PipelineDispatcher',
        'App\Services\Procurement\PipelineRecoveryReconciler',
        'App\Services\Procurement\PlatformRequestBudget',
        'App\Services\Procurement\SourceMethodFactory',
        'App\Services\Procurement\OperationLogWriter',
        'App\Services\Procurement\FetchLogger',
        'App\Services\Procurement\SourceControl\SourceCircuitBreaker',
        'App\Services\Procurement\Proxy\ProxyCircuitBreaker',
        'App\Services\Procurement\Proxy\ProxyScoreCalculator',
        'App\Services\Procurement\SourceControl\SuspendedRunResumer',
        'App\Services\Procurement\SourceControl\SourceRunControl',
        'App\Models\Procurement\Crawler\SourceIncident',
        'App\Models\Procurement\Crawler\Fetch',
        'App\Models\Procurement\OperationLog',
        'App\Console\Commands\CrawlerProgress',
        'App\Console\Commands\PruneCrawlerHistory',
        'App\Console\Commands\Procurement\PipelineCleanupTemp',
        'App\Console\Commands\Procurement\PipelineStuckCheck',
        'App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource',
    ];

    /** @return list<string> */
    public static function violations(string $path, string $source): array
    {
        $errors = [];
        $inspection = PlatformSourceRules::inspect($source);
        if ($inspection['group_use']) {
            $errors[] = $path.': unsupported grouped ownership import';
        }
        $dependencies = $inspection['dependencies'];
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $literal = trim($token[1], "\"'");
                foreach (self::TABLES as $table) {
                    if (preg_match('/\b'.preg_quote($table, '/').'\b/', $literal) === 1) {
                        $errors[] = $path.': legacy table '.$table;
                    }
                }
                if (preg_match('/(?:config|->(?:get|string|set))\s*\(\s*(?:\[\s*)?$/', $code) === 1
                    && (str_starts_with($literal, 'crawler.') || str_starts_with($literal, 'ai.pipeline.')
                        || str_starts_with($literal, 'ai.retry.') || $literal === 'ai.tools.ocr_timeout_seconds')) {
                    $errors[] = $path.': legacy config reader '.$literal;
                }
                $code .= "'literal'";

                continue;
            }
            $code .= $token[1];
        }
        if (preg_match('/\b(?:class|trait)\s+(\w+)/', $code, $match) === 1) {
            $dependencies[] = $inspection['namespace'].'\\'.$match[1];
        }
        foreach ($dependencies as $symbol) {
            if (preg_match('~^app/Services/Procurement/(Process|Document|Screening|Extraction|Acquisition)/~', $path) === 1
                && in_array($symbol, ['App\Services\Procurement\Network\LegacyBridge', 'App\Services\Procurement\Network\LegacyOutboundClient', 'App\Services\Procurement\Network\Contracts\LegacyOutbound'], true)) {
                $errors[] = $path.': ordinary work bridge dependency '.$symbol;
            }
            if (in_array($symbol, self::SYMBOLS, true)
                || preg_match('/^App\\\\Jobs\\\\Procurement\\\\(?:.*\\\\)?(?:CrawlRunJob|FetchProcurementDetailJob|DownloadDocumentJob|PrepareDocumentJob|PipelineJob|ScreeningJob|EstimateExtractionJob|RevalidateDocumentJob|ShadowAnalysisJob)$/', $symbol) === 1
                || preg_match('/^App\\\\Models\\\\Procurement\\\\Concerns\\\\(?:HasLease|HasErrorBlock)$/', $symbol) === 1) {
                $errors[] = $path.': legacy symbol '.$symbol;
            }
        }

        return array_values(array_unique($errors));
    }
}
