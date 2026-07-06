<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use Illuminate\Support\Facades\Log;

/**
 * Тонкий мост crawling → пайплайн документов (`02-crawling.md` §8): отбирает документы
 * закупки, УЖЕ скачанные `DocumentDownloader` (`downloaded` — есть контент, есть что
 * готовить), и уведомляет будущего consumer'а стадии подготовки (Фаза 4, вне V2-CRAWL).
 * `discovered` (контент ещё не скачан — сеть недоступна/ретраи не исчерпаны/файл без
 * ссылки) сюда намеренно не попадает: готовить нечего (FSM `03-document-pipeline.md` §2 —
 * `discovered ──download──> downloaded ──prepare──> prepared`). Диспатч не мутирует
 * `prc_documents.status` — нет отдельного поля-маркера «поставлено в очередь» в схеме
 * (`01-database.md` §5), а следующий переход (`downloaded→prepared`) — вне V2-CRAWL,
 * владеет им будущий consumer Фазы 4; guard от дублей — сам предикат выборки.
 */
final readonly class PipelineDispatcher
{
    public function dispatch(Procurement $procurement): int
    {
        $documents = Document::query()
            ->where('procurement_id', $procurement->id)
            ->where('status', DocumentStatus::Downloaded)
            ->whereNull('removed_at')
            ->get();

        foreach ($documents as $document) {
            Log::info(message: 'PipelineDispatcher: документ поставлен в очередь стадии подготовки.', context: [
                'procurement_id' => $procurement->id,
                'document_id' => $document->id,
            ]);
        }

        return $documents->count();
    }
}
