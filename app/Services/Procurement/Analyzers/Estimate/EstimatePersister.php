<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Analyzers\Estimate\EstimateWorkType;
use App\Dto\Procurement\Analyzers\Estimate\IncompleteRow;
use App\Dto\Procurement\Analyzers\Estimate\RowAttributes;
use App\Dto\Procurement\Analyzers\Estimate\RowModifier;
use App\Dto\Procurement\Analyzers\Estimate\RowResource;
use App\Enums\Procurement\Estimate\RowType;
use App\Enums\Procurement\Estimate\TotalKind;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Repositories\Procurement\Analyzers\Estimate\EstimateStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Storage\FileLocationResolver;
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
        private FileLocationResolver $locationResolver,
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
        $workTypes = array_map(static fn (array $workType): EstimateWorkType => EstimateWorkType::from($workType), (array) ($data['work_types'] ?? []));

        $stitched = $this->stitcher->stitch($rows, $incompleteItems);
        $report = $this->validator->validate((int) ($data['rows_expected'] ?? 0), $stitched->rows, $totals);

        $ordinal = (int) $data['ordinal'];
        $estimateNumber = $data['estimate_number'] ?? null;
        $itemRows = array_values(array_filter($stitched->rows, static fn (EstimateRow $row): bool => $row->row_type === RowType::Item));

        DB::transaction(function () use ($analysis, $document, $version, $ordinal, $estimateNumber, $data, $stitched, $itemRows, $report, $totals, $workTypes): void {
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
                    'regulatory_base' => $data['regulatory_base'] ?? null,
                    'passport' => $data['passport'] ?? null,
                    'calc_method' => $data['calc_method'] ?? null,
                    'labor_hours_workers' => $data['labor_hours_workers'] ?? null,
                    'labor_hours_machinists' => $data['labor_hours_machinists'] ?? null,
                    'document_number' => $data['document_number'] ?? null,
                    'document_date' => $data['document_date'] ?? null,
                    'period_start' => $data['period_start'] ?? null,
                    'period_end' => $data['period_end'] ?? null,
                    ...$this->rawResultAttributes($analysis->id, $ordinal, $data),
                    'rows_expected' => (int) ($data['rows_expected'] ?? 0),
                    'rows_extracted' => count($itemRows),
                    'validation_status' => $report->status->value,
                ],
            );

            $this->estimates->replaceWorkTypes($estimate->id, $workTypes);

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
                    ...self::itemAttributes($row),
                    'work_type_id' => $this->resolveWorkTypeId($estimate->id, $row->work_type_name),
                ],
            );

            $this->estimates->replaceResources(
                $item,
                array_map(static function (RowResource $resource): array {
                    $unit = UnitNormalizer::normalize($resource->unit);

                    return [
                        'resource_type' => $resource->resource_type,
                        'code' => $resource->code,
                        'name' => $resource->name,
                        'unit' => $resource->unit,
                        'unit_base' => $unit['unit_base'],
                        'unit_multiplier' => $unit['unit_multiplier'],
                        'quantity' => $resource->quantity,
                        'price_base' => $resource->price_base,
                        'price_curr' => $resource->price_curr,
                        'index_code' => $resource->index_code,
                        'index_value' => $resource->index_value,
                    ];
                }, $row->resources),
            );
        }
    }

    /**
     * Единственный носитель набора полей позиции (`prc_estimate_items`), читаемого ОБОИМИ
     * writer-путями (`self`/`RerunRangeAction`, P5.2 Implementation Rules п.2) — две независимые
     * копии этого набора и есть причина, по которой полный persist и точечный re-run расходились
     * (D40). `work_type_id` сюда не входит: резолв `work_type_name` → FK читает состояние БД
     * (`EstimateStoreRepository::findOrCreateWorkType()`, P5.3, B-5) — `itemAttributes()` остаётся
     * чистой функцией строки, вызывающая сторона мёржит `work_type_id` отдельным ключом.
     *
     * @return array<string, mixed>
     */
    public static function itemAttributes(EstimateRow $row): array
    {
        $unit = UnitNormalizer::normalize($row->unit);

        return [
            'code' => $row->code,
            'name' => $row->name,
            'unit' => $row->unit,
            'unit_base' => $unit['unit_base'],
            'unit_multiplier' => $unit['unit_multiplier'],
            'quantity' => $row->quantity,
            'unit_price_base' => $row->unit_price_base,
            'unit_price_curr' => $row->unit_price_curr,
            'total_price_base' => $row->total_price_base,
            'total_price_curr' => $row->total_price_curr,
            'index_code' => $row->index_code,
            'index_value' => $row->index_value,
            'source_position_number' => $row->source_position_number,
            'attributes' => self::rowAttributes($row->attributes),
        ];
    }

    /**
     * Собирается по `RowAttributes::ALLOWED_KEYS` (единственный источник истины допустимых ключей,
     * P5.2 Implementation Rules п.3), а не перечислением ключей руками — пустое значение не
     * превращается в `null`-ключ.
     *
     * @return array<string, mixed>|null
     */
    private static function rowAttributes(?RowAttributes $attributes): ?array
    {
        if ($attributes === null) {
            return null;
        }

        $values = [];

        foreach (RowAttributes::ALLOWED_KEYS as $key) {
            $value = $attributes->{$key};

            if ($key === 'modifiers') {
                if ($value !== []) {
                    $values['modifiers'] = array_map(static fn (RowModifier $modifier): array => $modifier->toArray(), $value);
                }

                continue;
            }

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values === [] ? null : $values;
    }

    /**
     * `EstimateRow::$work_type_name` пуст на боевом LLM-ответе только строкой без вида работ —
     * это законное состояние, не потеря (P5.3, B-5).
     */
    private function resolveWorkTypeId(int $estimateId, ?string $workTypeName): ?int
    {
        if ($workTypeName === null) {
            return null;
        }

        return $this->estimates->findOrCreateWorkType($estimateId, $workTypeName)->id;
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
     * @return array{raw_result: array<string, mixed>|null, raw_result_ref: string|null, raw_result_disk: string|null}
     */
    private function rawResultAttributes(int $analysisId, int $ordinal, array $data): array
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        if (strlen($encoded) <= self::RAW_RESULT_INLINE_MAX_BYTES) {
            return ['raw_result' => $data, 'raw_result_ref' => null, 'raw_result_disk' => null];
        }

        $disk = $this->locationResolver->currentRawDisk();
        $path = sprintf('ai/estimates/%d/%d-%s.json', $analysisId, $ordinal, (string) Str::uuid());
        Storage::disk($disk)->put($path, $encoded);

        return ['raw_result' => null, 'raw_result_ref' => $path, 'raw_result_disk' => $disk];
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
