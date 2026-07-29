<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Ai\Estimate\IncompleteRow;
use App\Dto\Procurement\Ai\Estimate\RowModifier;
use App\Dto\Procurement\Ai\Estimate\RowResource;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\RowType;
use App\Enums\Procurement\TotalKind;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Repositories\Procurement\EstimateStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Нормализация `analysis.result.estimates[]` (P6.1-формат, сырые чанки построчного извлечения)
 * в `prc_estimate_*` (ТЗ 03 §3.5 п.4-5, Scope Included P6.3). Для каждой сметы: `EstimateStitcher`
 * (P6.2) сливает чанки строк/буфер незавершённых строк → `EstimateValidator` (P6.2) считает
 * `validation_status`/аномалии → результат пишется ОДНОЙ транзакцией на смету (Implementation
 * Rules P6.3). Статус документа `screened → extracted` УЖЕ устанавливает `ExtractionOrchestrator`
 * (P6.1, Implementation Rules P6.1 «финализация обязательна в любом исходе») — Persister читает
 * `analysis.result` как источник и не дублирует переход статуса.
 */
final readonly class EstimatePersister
{
    /**
     * Порог inline vs `raw_result_ref` (миграция `100033`: «object storage только для payload
     * > ~1МБ»).
     */
    private const int RAW_RESULT_INLINE_MAX_BYTES = 1_000_000;

    public function __construct(
        private EstimateStoreRepository $estimates,
        private ProcurementStoreRepository $procurements,
        private EstimateStitcher $stitcher,
        private EstimateValidator $validator,
    ) {}

    /**
     * `$supersededAnalysisId` (P9.2, D24) — сметы этого (заменённого повторным прогоном) анализа
     * удаляются В ТОЙ ЖЕ транзакции, что персист новых: история прогона остаётся в
     * `prc_ai_analyses`/`llm_calls`, дублей витрины смет на документ нет. `null` —
     * обычный (не re-run) прогон, поведение не меняется.
     */
    public function persist(Analysis $analysis, ?int $supersededAnalysisId = null): void
    {
        $version = $analysis->documentVersion;
        $document = Document::query()->findOrFail($version->document_id);

        /** @var list<array<string, mixed>> $estimatesData */
        $estimatesData = (array) ($analysis->result['estimates'] ?? []);

        if ($supersededAnalysisId !== null) {
            DB::transaction(function () use ($supersededAnalysisId, $analysis, $document, $version, $estimatesData): void {
                $this->estimates->deleteByAnalysisId($supersededAnalysisId);

                foreach ($estimatesData as $data) {
                    $this->persistEstimate($analysis, $document, $version, (array) $data);
                }
            });

            return;
        }

        foreach ($estimatesData as $data) {
            $this->persistEstimate($analysis, $document, $version, (array) $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistEstimate(Analysis $analysis, Document $document, DocumentVersion $version, array $data): void
    {
        $rows = array_map(static fn (array $row): EstimateRow => EstimateRow::from($row), (array) ($data['rows'] ?? []));
        $incompleteItems = array_map(static fn (array $row): IncompleteRow => IncompleteRow::from($row), (array) ($data['incomplete_items'] ?? []));
        $totals = isset($data['totals']) && $data['totals'] !== null ? EstimateTotalsBlock::from($data['totals']) : null;

        $stitched = $this->stitcher->stitch($rows, $incompleteItems);
        $report = $this->validator->validate((int) ($data['rows_expected'] ?? 0), $stitched->rows, $totals);

        $ordinal = (int) $data['ordinal'];
        $estimateNumber = $data['estimate_number'] ?? null;
        $itemRows = array_values(array_filter($stitched->rows, static fn (EstimateRow $row): bool => $row->row_type === RowType::Item));

        DB::transaction(function () use ($analysis, $document, $version, $ordinal, $estimateNumber, $data, $stitched, $itemRows, $report, $totals): void {
            $estimate = $this->estimates->upsertEstimate(
                analysisId: $analysis->id,
                ordinal: $ordinal,
                attributes: [
                    'document_version_id' => $version->id,
                    'procurement_id' => $document->procurement_id,
                    'platform_id' => $document->platform_id,
                    // `title` — вычисляется аксессором Estimate::title() на чтении (D100), сюда не пишется.
                    'estimate_number' => $estimateNumber,
                    'total_amount' => $this->grandTotal($totals),
                    'currency' => $data['currency'] ?? 'RUB',
                    'base_year' => $data['base_year'] ?? null,
                    'price_level' => $data['price_level'] ?? null,
                    'passport' => $data['passport'] ?? null,
                    ...$this->rawResultAttributes($analysis->id, $ordinal, $data),
                    'rows_expected' => (int) ($data['rows_expected'] ?? 0),
                    'rows_extracted' => count($itemRows),
                    'validation_status' => $report->status->value,
                ],
            );

            $this->persistRows($estimate, $stitched->rows, $stitched->sectionPaths);

            if ($totals !== null) {
                $this->estimates->replaceTotals($estimate->id, $totals->footer);
            }

            $this->storeEvent($document, $estimate);
        });
    }

    /**
     * @param  list<EstimateRow>  $rows
     * @param  array<int, list<string>>  $sectionPaths
     */
    private function persistRows(Estimate $estimate, array $rows, array $sectionPaths): void
    {
        $sectionIdCache = [];
        $childPositionCounters = [];

        foreach ($rows as $row) {
            if (in_array($row->row_type, [RowType::SectionHeader, RowType::SubsectionHeader], strict: true)) {
                // Заголовок раздела/подраздела — резолвит/создаёт Section, отдельной Item-записи
                // не порождает (Item-таблица хранит только позиции с расценкой, D10).
                $this->resolveSectionId($estimate->id, $sectionPaths[$row->row_index] ?? [], $sectionIdCache, $childPositionCounters);

                continue;
            }

            if ($row->row_type !== RowType::Item) {
                // Subtotal — использован EstimateValidator для арифметики раздела, отдельной
                // записи не имеет (нет слота в схеме sections/items под промежуточный итог).
                continue;
            }

            $sectionId = $this->resolveSectionId($estimate->id, $sectionPaths[$row->row_index] ?? [], $sectionIdCache, $childPositionCounters);

            $item = $this->estimates->upsertItem(
                estimateId: $estimate->id,
                sectionId: $sectionId,
                rowIndex: $row->row_index,
                attributes: [
                    'code' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'quantity' => $row->quantity,
                    'unit_price_curr' => $row->unit_price_curr,
                    'total_price_curr' => $row->total_price_curr,
                    'attributes' => $row->attributes !== null && $row->attributes->modifiers !== []
                        ? ['modifiers' => array_map(static fn (RowModifier $modifier): array => $modifier->toArray(), $row->attributes->modifiers)]
                        : null,
                ],
            );

            $this->estimates->replaceResources(
                $item,
                array_map(static fn (RowResource $resource): array => [
                    'resource_type' => $resource->resource_type,
                    'code' => $resource->code,
                    'name' => $resource->name,
                    'unit' => $resource->unit,
                    'quantity' => $resource->quantity,
                    'price_curr' => $resource->price_curr,
                ], $row->resources),
            );
        }
    }

    /**
     * @param  list<string>  $breadcrumb
     * @param  array<string, int>  $sectionIdCache
     * @param  array<string, int>  $childPositionCounters
     */
    private function resolveSectionId(int $estimateId, array $breadcrumb, array &$sectionIdCache, array &$childPositionCounters): ?int
    {
        if ($breadcrumb === []) {
            return null;
        }

        $parentId = null;
        $parentKey = 'root';
        $pathSoFar = [];

        foreach ($breadcrumb as $title) {
            $pathSoFar[] = $title;
            $key = implode(' > ', $pathSoFar);

            if (! isset($sectionIdCache[$key])) {
                $childPositionCounters[$parentKey] = ($childPositionCounters[$parentKey] ?? 0) + 1;

                $section = $this->estimates->findOrCreateSection(
                    estimateId: $estimateId,
                    parentId: $parentId,
                    title: $title,
                    position: $childPositionCounters[$parentKey],
                    sectionNumber: null,
                );

                $sectionIdCache[$key] = $section->id;
            }

            $parentId = $sectionIdCache[$key];
            $parentKey = $key;
        }

        return $parentId;
    }

    /**
     * Best-effort грандтотал (та же эвристика `kind='total'`/«итого»/«всего», что
     * `EstimateValidator::checkGrandTotal()`, P6.2 Known Deviations — точная таксономия `kind`
     * спекой не зафиксирована) — источник `prc_estimates.total_amount`.
     */
    private function grandTotal(?EstimateTotalsBlock $totals): ?string
    {
        if ($totals === null) {
            return null;
        }

        foreach ($totals->footer as $line) {
            $label = mb_strtolower($line->label);

            if ($line->kind === TotalKind::Total || str_contains($label, 'итого') || str_contains($label, 'всего')) {
                return $line->value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{raw_result: array<string, mixed>|null, raw_result_ref: string|null}
     */
    private function rawResultAttributes(int $analysisId, int $ordinal, array $data): array
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        if (strlen($encoded) <= self::RAW_RESULT_INLINE_MAX_BYTES) {
            return ['raw_result' => $data, 'raw_result_ref' => null];
        }

        $path = sprintf('ai/estimates/%d/%d-%s.json', $analysisId, $ordinal, (string) Str::uuid());
        Storage::disk((string) config('crawler.raw_disk'))->put($path, $encoded);

        return ['raw_result' => null, 'raw_result_ref' => $path];
    }

    private function storeEvent(Document $document, Estimate $estimate): void
    {
        if ($document->procurement_id === null) {
            return;
        }

        $this->procurements->storeEvent(
            procurementId: $document->procurement_id,
            eventType: ProcurementEventType::EstimateExtracted,
            occurredAt: now(),
            entityRef: ['document_id' => $document->id, 'estimate_id' => $estimate->id],
            newValue: $estimate->validation_status,
            payload: ['ordinal' => $estimate->ordinal, 'rows_extracted' => $estimate->rows_extracted],
        );
    }
}
