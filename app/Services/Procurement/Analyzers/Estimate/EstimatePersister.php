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
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Estimate\RowQualityReason;
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
     * `$supersededAnalysisId` (P9.2, D24) — сметы всех superseded-анализов этой версии
     * удаляются В ТОЙ ЖЕ транзакции, что персист новых: история прогона остаётся в
     * `prc_ai_analyses`/`llm_calls`, дублей витрины смет на документ нет. `null` —
     * обычный (не re-run) прогон, поведение не меняется.
     */
    public function persist(Analysis $analysis, ?int $supersededAnalysisId = null): void
    {
        $version = $analysis->documentVersion;

        if ($version === null) {
            throw new \LogicException("Analysis #{$analysis->id} не содержит document version.");
        }

        $document = Document::query()->findOrFail($version->document_id);

        $estimatesData = $this->objectList($analysis->result['estimates'] ?? [], 'analysis.result.estimates');

        if ($supersededAnalysisId !== null) {
            DB::transaction(function () use ($analysis, $document, $version, $estimatesData): void {
                $this->estimates->deleteSupersededByVersion($version->id);

                foreach ($estimatesData as $data) {
                    $this->persistEstimate($analysis, $document, $version, $data);
                }
            });

            return;
        }

        foreach ($estimatesData as $data) {
            $this->persistEstimate($analysis, $document, $version, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistEstimate(Analysis $analysis, Document $document, DocumentVersion $version, array $data): void
    {
        $rows = $this->estimateRows($data['rows'] ?? []);
        $incompleteItems = $this->incompleteRows($data['incomplete_items'] ?? []);
        $totals = ($data['totals'] ?? null) === null ? null : EstimateTotalsBlock::from($this->jsonObject($data['totals'], 'estimate totals'));
        $workTypes = $this->workTypes($data['work_types'] ?? []);

        $stitched = $this->stitcher->stitch($rows, $incompleteItems);
        $report = $this->validator->validate($this->integerValue($data['rows_expected'] ?? 0, 'estimate rows_expected'), $stitched->rows, $totals);
        $report = $this->withStitchAnomalies($report, $stitched);

        $ordinal = $this->integerValue($data['ordinal'] ?? null, 'estimate ordinal');
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
                    'rows_expected' => $this->integerValue($data['rows_expected'] ?? 0, 'estimate rows_expected'),
                    'rows_extracted' => count($itemRows),
                    'validation_status' => $report->status->value,
                    'validation_notes' => $report->notes === [] ? null : $report->notes,
                ],
            );

            $this->estimates->replaceWorkTypes($estimate->id, $workTypes);

            $this->persistRows($estimate, $stitched->rows, $stitched->sectionPaths, $report);

            if ($totals !== null) {
                $this->estimates->replaceTotals($estimate->id, array_values($totals->footer));
            }

            $this->storeEvent($document, $estimate);
        });
    }

    private function withStitchAnomalies(ValidationReport $report, StitchReport $stitched): ValidationReport
    {
        if (! $stitched->hasAnomalies()) {
            return $report;
        }

        $notes = $report->notes;

        if ($stitched->droppedDuplicateRowIndexes !== []) {
            $notes[] = 'Повторные индексы строк при сборке: '.implode(', ', $stitched->droppedDuplicateRowIndexes).'. Сохранено первое вхождение; требуется сверка с источником.';
        }

        if ($stitched->sectionTitleMismatches !== []) {
            $notes[] = 'Расхождение разделов при сборке строк: '.implode(', ', $stitched->sectionTitleMismatches).'.';
        }

        $incompleteCount = count($stitched->unmatchedIncompleteItems) + 2 * count($stitched->mergedIncompleteItems);

        if ($incompleteCount > 0) {
            $notes[] = "Неполные фрагменты строк: {$incompleteCount}. Сохранены в исходном результате, но не включены в позиции; требуется проверка.";
        }

        return new ValidationReport(
            status: $report->status === EstimateValidationStatus::Invalid ? $report->status : EstimateValidationStatus::Suspicious,
            anomalousRowIndexes: $report->anomalousRowIndexes,
            rowReasons: $report->rowReasons,
            rowNotes: $report->rowNotes,
            notes: $notes,
        );
    }

    /**
     * @param  list<EstimateRow>  $rows
     * @param  array<int, list<string>>  $sectionPaths
     */
    private function persistRows(Estimate $estimate, array $rows, array $sectionPaths, ValidationReport $report): void
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
                    ...self::itemAttributes($row, $report),
                    'work_type_id' => $this->resolveWorkTypeId($estimate->id, $row->work_type_name),
                ],
            );

            $resourceRows = [];

            foreach ($row->resources as $resource) {
                $resourceRows[] = self::resourceAttributes($resource);
            }

            $this->estimates->replaceResources($item, $resourceRows);
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
    public static function itemAttributes(EstimateRow $row, ?ValidationReport $report = null): array
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
            'quality' => self::rowQuality($row, $report),
        ];
    }

    /**
     * Слияние ДВУХ независимых утверждений о строке под провенанс-ключом верхнего уровня
     * (research/12-uncertainty-model.md §3.2/§3.3): `model` — контрактная форма модели (P8.3),
     * как есть; `validator` — построчная карта причин `ValidationReport` (P8.4). Оба входа пусты →
     * `null` (чистая позиция, И-А §6.1). `quality` возвращается ВСЕГДА (в т.ч. `null`) — атрибут,
     * который писатель молча пропускает, оставляет пометку прошлого прогона (Ф-10). Точечный
     * re-run (`RerunRangeAction`) вызывает этот метод без `$report` (нет `ValidationReport` без
     * полного стичинга сметы, §7 докблок класса) — ключ `validator` в записи исчезает, ключ
     * `model` перезаписывается новым ответом (правило re-run, research/12 §7).
     *
     * @return array{model?: array<string, mixed>, validator?: array{reasons: list<string>, notes: list<string>}}|null
     */
    private static function rowQuality(EstimateRow $row, ?ValidationReport $report): ?array
    {
        $quality = [];

        if ($row->quality !== null) {
            /** @var array<string, mixed> $modelQuality */
            $modelQuality = $row->quality->toArray();
            $quality['model'] = $modelQuality;
        }

        if ($report !== null) {
            $reasons = $report->rowReasons[$row->row_index] ?? [];

            if ($reasons !== []) {
                $quality['validator'] = [
                    'reasons' => array_map(static fn (RowQualityReason $reason): string => $reason->value, $reasons),
                    'notes' => $report->rowNotes[$row->row_index] ?? [],
                ];
            }
        }

        return $quality === [] ? null : $quality;
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

        if ($attributes->modifiers !== []) {
            $values['modifiers'] = array_map(static fn (RowModifier $modifier): array => $modifier->toArray(), $attributes->modifiers);
        }

        foreach ([
            'amount_since_start' => $attributes->amount_since_start,
            'amount_since_year_start' => $attributes->amount_since_year_start,
            'amount_reporting_period' => $attributes->amount_reporting_period,
        ] as $key => $value) {
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
        if (Storage::disk($disk)->put($path, $encoded) !== true) {
            throw new \RuntimeException('Не удалось сохранить исходный результат сметы.');
        }

        return ['raw_result' => null, 'raw_result_ref' => $path, 'raw_result_disk' => $disk];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceAttributes(RowResource $resource): array
    {
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

    /**
     * @return list<array<string, mixed>>
     */
    private function objectList(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw new \LogicException("{$context} должен быть списком объектов.");
        }

        $objects = [];

        foreach ($value as $entry) {
            $objects[] = $this->jsonObject($entry, $context);
        }

        return $objects;
    }

    /**
     * @return list<EstimateRow>
     */
    private function estimateRows(mixed $value): array
    {
        $rows = [];

        foreach ($this->objectList($value, 'estimate rows') as $row) {
            $rows[] = EstimateRow::from($row);
        }

        return $rows;
    }

    /**
     * @return list<IncompleteRow>
     */
    private function incompleteRows(mixed $value): array
    {
        $rows = [];

        foreach ($this->objectList($value, 'estimate incomplete_items') as $row) {
            $rows[] = IncompleteRow::from($row);
        }

        return $rows;
    }

    /**
     * @return list<EstimateWorkType>
     */
    private function workTypes(mixed $value): array
    {
        $workTypes = [];

        foreach ($this->objectList($value, 'estimate work_types') as $workType) {
            $workTypes[] = EstimateWorkType::from($workType);
        }

        return $workTypes;
    }

    private function integerValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \LogicException("{$context} должен быть целым числом.");
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \LogicException("{$context} должен быть JSON-объектом.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
