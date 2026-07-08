<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\Estimate\RowModifier;
use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Enums\Procurement\LlmPurpose;
use App\Enums\Procurement\RowType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Repositories\Procurement\EstimateStoreRepository;
use App\Services\Llm\AiRouteResolver;
use App\Services\Llm\Contracts\SupportsFileUpload;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Точечное переизвлечение аномального/подозрительного диапазона строк (Scope Included P6.3,
 * ТЗ 03 §3.5 п.5 «не по всему документу») — заменяет ТОЛЬКО позиции запрошенного диапазона,
 * остальные строки сметы не трогает (Implementation Rules P6.3: точечный upsert по `row_index`,
 * не `deleteWhere+insert`). Переиспользует `ChunkSource::fetchRows()` (тот же контракт, что
 * основной цикл `ExtractionOrchestrator`, P6.1) и Files-хэндл из `analysis.progress`, если тот
 * ещё не истёк (тот же приём переиспользования, что `ExtractionOrchestrator::resolveFileHandle()`
 * — здесь минимальная копия ТОЛЬКО чтения хэндла, без записи `progress` обратно: точечный re-run
 * не участвует в checkpoint-цикле P6.1).
 *
 * НЕ пересчитывает `validation_status`/иерархию секций сметы целиком — секция замененной строки
 * сохраняется прежней (Known Deviations P6.3): полный пересчёт требует Stitcher/Validator над
 * ВСЕМИ строками сметы с исходным `row_type`, которого `prc_estimate_items` не хранит (только
 * позиции — заголовки/subtotal в этой таблице не персистятся, `EstimatePersister`). Обновляет
 * только `rows_extracted` (счётчик персистированных позиций).
 */
final readonly class RerunRangeAction
{
    public function __construct(
        private AiRouteResolver $routes,
        private ChunkSource $chunkSource,
        private EstimateStoreRepository $estimates,
    ) {}

    public function run(Estimate $estimate, int $rowStart, int $rowLength): void
    {
        $analysis = $estimate->analysis;
        $version = $estimate->documentVersion;

        $route = $this->routes->resolve($analysis->document_type ?? 'estimate', LlmPurpose::ExtractRows);
        $session = $this->buildSession($analysis, $version, $route);

        $result = $this->chunkSource->fetchRows($analysis->id, $route, $session, $estimate->ordinal, $rowStart, $rowLength);

        /** @var RowsChunkResponse $rows */
        $rows = $result->data;

        foreach ($rows->rows as $row) {
            if ($row->row_type !== RowType::Item) {
                // Заголовок/subtotal внутри диапазона — не переструктурируем иерархию раздела
                // без полного стичинга сметы (см. докблок класса).
                continue;
            }

            $existing = Item::query()
                ->where('estimate_id', $estimate->id)
                ->where('row_index', $row->row_index)
                ->first();

            $this->estimates->upsertItem(
                estimateId: $estimate->id,
                sectionId: $existing?->section_id,
                rowIndex: $row->row_index,
                attributes: [
                    'code' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'quantity' => $row->quantity,
                    'unit_price' => $row->unit_price,
                    'total_price' => $row->total_price,
                    'attributes' => $row->modifiers !== []
                        ? ['modifiers' => array_map(static fn (RowModifier $modifier): array => $modifier->toArray(), $row->modifiers)]
                        : null,
                ],
            );
        }

        $estimate->update(['rows_extracted' => Item::query()->where('estimate_id', $estimate->id)->count()]);
    }

    private function buildSession(Analysis $analysis, DocumentVersion $version, ResolvedRoute $route): ExtractionSession
    {
        $progress = $this->progressArray($analysis);

        $uri = $progress['file_uri'] ?? null;
        $expiresAt = $progress['file_expires_at'] ?? null;

        $fileHandle = is_string($uri) && is_string($expiresAt) && CarbonImmutable::parse($expiresAt)->isFuture()
            ? new LlmFileHandle(uri: $uri, expiresAt: CarbonImmutable::parse($expiresAt))
            : $this->uploadEffectiveFile($version, $route);

        return new ExtractionSession(
            fileHandle: $fileHandle,
            cacheHandle: null,
            usage: new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0),
            progress: $progress,
        );
    }

    private function uploadEffectiveFile(DocumentVersion $version, ResolvedRoute $route): LlmFileHandle
    {
        if (! $route->provider instanceof SupportsFileUpload) {
            throw new RuntimeException('Провайдер маршрута точечного re-run не поддерживает Files API.');
        }

        $effective = $this->resolveEffectiveFile($version);
        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localPath = tempnam(sys_get_temp_dir(), 'rerun_').'.'.$effective['extension'];
        file_put_contents($localPath, $disk->get($effective['storagePath']));

        try {
            return $route->provider->uploadFile($localPath, $effective['mime']);
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * @return array{storagePath: string, extension: string, mime: string}
     */
    private function resolveEffectiveFile(DocumentVersion $version): array
    {
        if ($version->prepared_storage_path !== null) {
            return ['storagePath' => $version->prepared_storage_path, 'extension' => 'pdf', 'mime' => 'application/pdf'];
        }

        return ['storagePath' => $version->storage_path, 'extension' => $version->extension, 'mime' => (string) $version->mime];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressArray(Analysis $analysis): array
    {
        $progress = $analysis->progress;

        if ($progress === null) {
            return [];
        }

        return $progress instanceof Collection ? $progress->toArray() : (array) $progress;
    }
}
