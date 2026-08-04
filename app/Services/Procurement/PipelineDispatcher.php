<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Мост crawling → пайплайн документов (`02-crawling.md` §8, ТЗ 03 §4): реальный dispatch
 * `PrepareDocumentJob` (не заглушка, P4.3) для документов, УЖЕ скачанных `DocumentDownloader`
 * (`downloaded` — есть контент, есть что готовить). `discovered` (контент ещё не скачан) сюда
 * намеренно не попадает (FSM `03-document-pipeline.md` §2 — `discovered ──download──>
 * downloaded ──prepare──> prepared`).
 *
 * Два канала (research/L finding 12 — прямой путь основной, поллер страховка):
 * - `dispatch()` — немедленный dispatch (`afterCommit()`) сразу после того, как закупка
 *   получила детальный срез (вызывается из `FetchDetails` тем же тиком, что и раньше —
 *   латентность v1-моста была только-поллером, здесь так не делаем);
 * - `dispatchBacklog()` — everyMinute-поллер (`routes/console.php`) по ВСЕМ закупкам,
 *   добирает пропущенное/несработавшее без привязки к конкретной закупке.
 *
 * Диспатч не мутирует `prc_documents.status` — следующий переход (`downloaded→prepared`)
 * делает сам `PrepareDocumentJob`; защита от дублей — `ShouldBeUnique` на job'е (P4.1 D39) +
 * status-guard в его `handle()`, а не предикат выборки здесь.
 */
final readonly class PipelineDispatcher
{
    public function dispatch(Procurement $procurement): int
    {
        return $this->enqueue(
            $this->downloadedQuery()
                ->where('procurement_id', $procurement->id)
                ->limit((int) config('ai.pipeline.dispatch_batch_limit', 100)),
        );
    }

    public function dispatchBacklog(int $limit): int
    {
        return $this->enqueue($this->downloadedQuery()->limit($limit));
    }

    /**
     * `pendingRetry()` (`HasErrorBlock`-канон, `Document::scopePendingRetry`) не даёт
     * прямому пути/поллеру обходить backoff и dead-letter (ревью-находка P4.3 HIGH): для
     * ещё-не-провалившегося документа `next_retry_at` пуст — скоуп пропускает как обычно, а
     * провалившийся при подготовке (см. `HasErrorBlock::recordFailure` — статус остаётся
     * `Downloaded`, меняются только `attempts/next_retry_at/failed_permanently`) переотправляется
     * только когда наступил `next_retry_at`, и никогда — если `failed_permanently` (это зона
     * `pipeline:retry-failed`, единственной команды, которая умеет claim+dead-letter-гейт).
     *
     * @return Builder<Document>
     */
    private function downloadedQuery(): Builder
    {
        return Document::query()
            ->where('status', DocumentStatus::Downloaded)
            ->whereNull('removed_at')
            ->pendingRetry();
    }

    /**
     * @param  Builder<Document>  $query
     */
    private function enqueue(Builder $query): int
    {
        $documents = $query->get();

        foreach ($documents as $document) {
            PrepareDocumentJob::dispatch($document->id)->afterCommit();
        }

        return $documents->count();
    }
}
