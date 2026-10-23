<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Jobs\Procurement\RevalidateDocumentJob;
use App\Models\Procurement\Document\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Selects a bounded batch of existing source documents for safe byte revalidation. */
final readonly class DocumentRevalidationSelector
{
    public function dispatchForProcurement(int $procurementId): int
    {
        return $this->dispatchDue(
            limit: $this->positiveIntegerConfig('crawler.documents_revalidation_revision_batch_limit', 25),
            procurementId: $procurementId,
        );
    }

    public function dispatchDue(int $limit, ?int $procurementId = null): int
    {
        if (! config('crawler.downstream_enabled', true)) {
            return 0;
        }

        $documents = $this->due(limit: $limit, procurementId: $procurementId);

        foreach ($documents as $document) {
            RevalidateDocumentJob::dispatch(documentId: $document->id)->afterCommit();
        }

        return $documents->count();
    }

    /** @return Collection<int, Document> */
    public function due(int $limit, ?int $procurementId = null): Collection
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Document revalidation limit must be positive.');
        }

        $dueBefore = now()->subDays($this->positiveIntegerConfig('crawler.documents_revalidation_after_days', 30));
        $rankedVersions = DB::table('prc_document_versions')
            ->select(['document_id', 'id', 'downloaded_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY document_id ORDER BY downloaded_at DESC, id DESC) AS version_rank');
        $latestVersions = DB::query()
            ->fromSub($rankedVersions, 'ranked_document_versions')
            ->select([
                'ranked_document_versions.document_id',
                'ranked_document_versions.id AS latest_version_id',
                'ranked_document_versions.downloaded_at AS latest_downloaded_at',
            ])
            ->where('ranked_document_versions.version_rank', 1);

        return Document::query()
            ->select('prc_documents.*')
            ->joinSub($latestVersions, 'latest_versions', static function (JoinClause $join): void {
                $join->on('latest_versions.document_id', '=', 'prc_documents.id');
            })
            ->join('prc_procurements', 'prc_procurements.id', '=', 'prc_documents.procurement_id')
            ->when($procurementId !== null, static fn (Builder $query): Builder => $query->where('prc_documents.procurement_id', $procurementId))
            ->whereNotNull('prc_documents.external_id')
            ->whereNotNull('prc_documents.source_url')
            ->whereNull('prc_documents.removed_at')
            ->where('prc_documents.failed_permanently', false)
            ->where(static function (Builder $query): void {
                $query->whereNull('prc_documents.next_retry_at')
                    ->orWhere('prc_documents.next_retry_at', '<=', now());
            })
            ->where(static function (Builder $query): void {
                $query->whereNull('prc_documents.locked_until')
                    ->orWhere('prc_documents.locked_until', '<', now());
            })
            ->whereNotExists(static function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('prc_ai_analysis_runs')
                    ->whereColumn('prc_ai_analysis_runs.document_version_id', 'latest_versions.latest_version_id')
                    ->whereNull('prc_ai_analysis_runs.finished_at');
            })
            ->whereNotExists(static function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('prc_ai_analyses')
                    ->join('prc_document_versions AS analysis_versions', 'analysis_versions.id', '=', 'prc_ai_analyses.document_version_id')
                    ->whereColumn('analysis_versions.document_id', 'prc_documents.id')
                    ->where('prc_ai_analyses.locked_until', '>=', now());
            })
            ->where(static function (Builder $query) use ($dueBefore): void {
                $query->whereColumn('prc_procurements.current_revision_detected_at', '>', 'latest_versions.latest_downloaded_at')
                    ->orWhere('latest_versions.latest_downloaded_at', '<=', $dueBefore);
            })
            ->orderByRaw('CASE WHEN prc_procurements.current_revision_detected_at > latest_versions.latest_downloaded_at THEN 0 ELSE 1 END')
            ->orderBy('latest_versions.latest_downloaded_at')
            ->orderBy('prc_documents.id')
            ->limit($limit)
            ->get();
    }

    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
