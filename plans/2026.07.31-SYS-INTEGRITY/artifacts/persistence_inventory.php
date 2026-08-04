<?php

declare(strict_types=1);

/**
 * P5.1 — инвентарь записи (задача (а)).
 *
 * Прогоняет РЕАЛЬНЫЙ gold-корпус (`estimates/gold/*.gold.json`, 6 файлов) через боевой писатель
 * `EstimatePersister::persist()` (тот же вход `analysis.result.estimates[]`, что и в бою — см.
 * `tests/Feature/Procurement/Analyzers/Estimate/EstimatePersisterTest.php`) и сравнивает непустые
 * поля gold с непустыми колонками БД ПОСЛЕ записи — класс A/B/C/D определяется по факту прогона,
 * не по чтению кода (Implementation Rules P5.1 п.4).
 *
 * Запуск (agelto_test, транзакция откатывается — DB не засоряется):
 *   docker exec -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
 *     -e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local \
 *     agelto_app php artisan tinker --execute="require base_path('plans/2026.07.31-SYS-INTEGRITY/artifacts/persistence_inventory.php');" \
 *     > plans/2026.07.31-SYS-INTEGRITY/artifacts/persistence_inventory.out.json
 *
 * RerunRangeAction — второй writer-путь — НЕ прогоняется здесь живым вызовом: `run()` внутри
 * дёргает `ChunkSource::fetchRows()` → реальный LLM-провайдер (запрещено Implementation Rules п.5).
 * Его `upsertItem(...)`-вызов (app/Services/Procurement/Analyzers/Estimate/RerunRangeAction.php:100-115)
 * — байт-в-байт тот же набор ключей attributes, что `EstimatePersister::persistRows()` (код читан,
 * не прогнан) МИНУС полный вызов `replaceResources()` (ресурсы этим путём не пишутся вовсе) —
 * это подтверждено чтением, а не прогоном, и отчёт называет это явно (не выдаёт за прогон).
 */

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource as EstimateResource;
use App\Models\Procurement\Estimate\Total;
use App\Services\Procurement\Analyzers\Estimate\EstimatePersister;

$dbName = (string) config('database.connections.'.config('database.default').'.database');

if (! str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "ABORT: активная БД [{$dbName}] не тестовая — не запускаю запись.\n");

    exit(1);
}

// prc_estimates: все колонки миграции 2026_07_04_100033 (33, включая id/uuid/timestamps).
const ESTIMATE_COLUMNS = [
    'id', 'uuid', 'analysis_id', 'document_version_id', 'procurement_id', 'platform_id', 'ordinal',
    'estimate_number', 'total_amount', 'currency', 'base_year', 'price_level', 'calc_method',
    'labor_hours_workers', 'labor_hours_machinists', 'document_number', 'document_date',
    'period_start', 'period_end', 'passport', 'raw_result', 'raw_result_ref', 'rows_expected',
    'rows_extracted', 'validation_status', 'verified_at', 'verified_by', 'source_sheet_name',
    'source_row_start', 'source_row_end', 'source_page_start', 'source_page_end',
    'created_at', 'updated_at',
];

// prc_estimate_sections: миграция 2026_07_04_100034 (7).
const SECTION_COLUMNS = ['id', 'estimate_id', 'parent_id', 'position', 'title', 'created_at', 'updated_at'];

// prc_estimate_items: миграция 2026_07_04_100035 (21).
const ITEM_COLUMNS = [
    'id', 'estimate_id', 'section_id', 'row_index', 'code', 'name', 'unit', 'unit_base',
    'unit_multiplier', 'quantity', 'unit_price_base', 'unit_price_curr', 'total_price_base',
    'total_price_curr', 'index_code', 'index_value', 'source_position_number', 'work_type_id',
    'attributes', 'created_at', 'updated_at',
];

// prc_estimate_resources: миграция 2026_07_04_100036 (15).
const RESOURCE_COLUMNS = [
    'id', 'item_id', 'resource_type', 'code', 'name', 'unit', 'unit_base', 'unit_multiplier',
    'quantity', 'price_base', 'price_curr', 'index_code', 'index_value', 'created_at', 'updated_at',
];

// prc_estimate_totals: миграция 2026_07_04_100037 (11).
const TOTAL_COLUMNS = [
    'id', 'estimate_id', 'parent_id', 'position', 'label', 'kind', 'amount', 'chapter_number',
    'rate', 'created_at', 'updated_at',
];

function isEmpty(mixed $v): bool
{
    if ($v === null) {
        return true;
    }
    if (is_string($v) && trim($v) === '') {
        return true;
    }
    if (is_array($v) && $v === []) {
        return true;
    }

    return false;
}

/** @var array<string, array{gold_nonempty:int, db_nonempty:int, both_nonempty:int}> $stats */
$stats = [];

function bump(array &$stats, string $col, bool $goldNonEmpty, bool $dbNonEmpty): void
{
    $stats[$col] ??= ['gold_nonempty' => 0, 'db_nonempty' => 0, 'both_nonempty' => 0];
    if ($goldNonEmpty) {
        $stats[$col]['gold_nonempty']++;
    }
    if ($dbNonEmpty) {
        $stats[$col]['db_nonempty']++;
    }
    if ($goldNonEmpty && $dbNonEmpty) {
        $stats[$col]['both_nonempty']++;
    }
}

$goldFiles = glob(base_path('estimates/gold/*.gold.json'));
sort($goldFiles);

$fileReports = [];

DB::beginTransaction();

try {
    $document = Document::factory()->create();

    foreach ($goldFiles as $path) {
        $code = basename($path, '.gold.json');
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $estimatesData = $decoded['estimates'];

        // Вход EstimatePersister — analysis.result.estimates[] (см. EstimatePersisterTest::
        // seedPersisterAnalysis) — gold несёт то же тело эстимейта БЕЗ ordinal/incomplete_items
        // (это поля обёртки P6.1-чанка, не PassportResponse) — добавляются здесь, не в gold.
        foreach ($estimatesData as $i => &$est) {
            $est['ordinal'] ??= $i + 1;
            $est['incomplete_items'] ??= [];
        }
        unset($est);

        $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
        $analysis = Analysis::factory()->create([
            'document_version_id' => $version->id,
            'step' => AnalysisStep::Extraction,
            'status' => AnalysisStatus::Succeeded,
            'result' => ['estimates' => $estimatesData, 'meta' => ['usage' => []]],
            'finished_at' => now(),
        ]);

        app(EstimatePersister::class)->persist($analysis);

        $fileReport = ['code' => $code, 'estimates' => count($estimatesData)];

        foreach ($estimatesData as $goldEst) {
            $dbEst = Estimate::query()
                ->where('analysis_id', $analysis->id)
                ->where('ordinal', $goldEst['ordinal'])
                ->firstOrFail();

            foreach (ESTIMATE_COLUMNS as $col) {
                if (! array_key_exists($col, $goldEst)) {
                    continue; // служебная/не в контракте — не считается по gold
                }
                bump($stats, "prc_estimates.$col", ! isEmpty($goldEst[$col]), ! isEmpty($dbEst->getAttribute($col)));
            }

            foreach (($goldEst['rows'] ?? []) as $goldRow) {
                if (($goldRow['row_type'] ?? null) !== 'item') {
                    continue;
                }

                $dbItem = Item::query()
                    ->where('estimate_id', $dbEst->id)
                    ->where('row_index', $goldRow['row_index'])
                    ->first();

                if ($dbItem === null) {
                    // stitcher может не материализовать строку (напр. дефект стыковки) — считаем
                    // как "не дошло" по ВСЕМ непустым полям строки, не пропускаем молча.
                    foreach (ITEM_COLUMNS as $col) {
                        if (array_key_exists($col, $goldRow)) {
                            bump($stats, "prc_estimate_items.$col", ! isEmpty($goldRow[$col]), false);
                        }
                    }

                    continue;
                }

                foreach (ITEM_COLUMNS as $col) {
                    if ($col === 'attributes') {
                        continue; // ниже — по подключам
                    }
                    if (array_key_exists($col, $goldRow)) {
                        bump($stats, "prc_estimate_items.$col", ! isEmpty($goldRow[$col]), ! isEmpty($dbItem->getAttribute($col)));
                    }
                }

                // work_type_name (контракт) -> work_type_id (колонка), связь по значению, не по имени ключа.
                bump($stats, 'prc_estimate_items.work_type_id', ! isEmpty($goldRow['work_type_name'] ?? null), ! isEmpty($dbItem->work_type_id));

                $goldAttrs = $goldRow['attributes'] ?? null;
                $dbAttrs = $dbItem->attributes?->toArray() ?? [];

                foreach (['amount_since_start', 'amount_since_year_start', 'amount_reporting_period'] as $key) {
                    $goldVal = $goldAttrs[$key] ?? null;
                    bump($stats, "prc_estimate_items.attributes.$key", ! isEmpty($goldVal), ! isEmpty($dbAttrs[$key] ?? null));
                }

                $goldMods = $goldAttrs['modifiers'] ?? [];
                $dbMods = $dbAttrs['modifiers'] ?? [];
                foreach ($goldMods as $mi => $goldMod) {
                    $dbMod = $dbMods[$mi] ?? null;
                    foreach (['name', 'kind', 'code', 'value', 'value_labor', 'value_machines', 'value_machinists_wage', 'value_materials', 'level'] as $mkey) {
                        bump(
                            $stats,
                            "attributes.modifiers[].$mkey",
                            ! isEmpty($goldMod[$mkey] ?? null),
                            $dbMod !== null && ! isEmpty($dbMod[$mkey] ?? null),
                        );
                    }
                }

                foreach (($goldRow['resources'] ?? []) as $ri => $goldRes) {
                    $dbRes = EstimateResource::query()->where('item_id', $dbItem->id)->orderBy('id')->get()[$ri] ?? null;

                    foreach (RESOURCE_COLUMNS as $col) {
                        if (! array_key_exists($col, $goldRes)) {
                            continue;
                        }
                        bump(
                            $stats,
                            "prc_estimate_resources.$col",
                            ! isEmpty($goldRes[$col]),
                            $dbRes !== null && ! isEmpty($dbRes->getAttribute($col)),
                        );
                    }
                }
            }

            // totals — рекурсивно, сопоставление по (label, position) в порядке обхода дерева.
            $walkTotals = function (array $goldLines, ?int $parentId) use (&$walkTotals, &$stats, $dbEst): void {
                $dbSiblings = Total::query()
                    ->where('estimate_id', $dbEst->id)
                    ->where('parent_id', $parentId)
                    ->orderBy('position')
                    ->get();

                foreach ($goldLines as $pos => $goldLine) {
                    $dbLine = $dbSiblings[$pos] ?? null;

                    foreach (['label', 'value', 'kind', 'chapter_number', 'rate'] as $key) {
                        $col = $key === 'value' ? 'amount' : $key;
                        bump(
                            $stats,
                            "prc_estimate_totals.$col",
                            ! isEmpty($goldLine[$key] ?? null),
                            $dbLine !== null && ! isEmpty($dbLine->getAttribute($col)),
                        );
                    }

                    if (($goldLine['children'] ?? []) !== []) {
                        $walkTotals($goldLine['children'], $dbLine?->id);
                    }
                }
            };

            if ($goldEst['totals'] ?? null) {
                $walkTotals($goldEst['totals']['footer'] ?? [], null);
            }
        }

        $fileReports[] = $fileReport;
    }

    // Синтетическая ДОПОЛНИТЕЛЬНАЯ проба (НЕ входит в gold-корпус, метится отдельно): реальный
    // 6-файловый gold не несёт ни одной строки summary_row/КС-3 (проверено — 0/6) — без синтетики
    // класс потери на КС-3-ключах attributes был бы не прогнан, а домыслен по чтению кода
    // (запрещено п.4). Вход собран из уже существующих DTO-контрактов, ничего в estimates/gold не
    // трогает и не расширяет корпус — это прогон КОДА на известном входе, не новый эталон.
    $synthRow = EstimateRow::from([
        'row_index' => 1,
        'row_type' => 'summary_row',
        'name' => 'Выполнено с начала года',
        'resources' => [],
        'attributes' => [
            'amount_since_start' => '1000.00',
            'amount_since_year_start' => '2000.00',
            'amount_reporting_period' => '500.00',
        ],
    ]);

    $synthDoc = Document::factory()->create();
    $synthVersion = DocumentVersion::factory()->create(['document_id' => $synthDoc->id]);
    $synthAnalysis = Analysis::factory()->create([
        'document_version_id' => $synthVersion->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'result' => ['estimates' => [[
            'ordinal' => 1,
            'estimate_number' => 'SYNTH-KS3',
            'rows_expected' => 1,
            'rows' => [$synthRow->toArray()],
            'incomplete_items' => [],
        ]], 'meta' => ['usage' => []]],
        'finished_at' => now(),
    ]);

    app(EstimatePersister::class)->persist($synthAnalysis);

    $synthEstimate = Estimate::query()->where('analysis_id', $synthAnalysis->id)->firstOrFail();
    $synthItem = Item::query()->where('estimate_id', $synthEstimate->id)->where('row_index', 1)->first();

    $synthResult = [
        'row_type_summary_row_materialized_as_item' => $synthItem !== null,
        'note' => $synthItem === null
            ? 'summary_row НЕ материализуется в prc_estimate_items (row_type !== Item, EstimatePersister::persistRows() continue) — КС-3-ключи attributes физически некуда писать этим путём вообще, независимо от прогона.'
            : 'summary_row материализован (неожиданно) — КС-3-ключи см. attributes',
    ];
} finally {
    DB::rollBack();
}

echo json_encode([
    'db' => $dbName,
    'gold_files' => $fileReports,
    'synthetic_summary_row_probe' => $synthResult,
    'column_stats' => $stats,
    'column_totals' => [
        'prc_estimates' => count(ESTIMATE_COLUMNS),
        'prc_estimate_sections' => count(SECTION_COLUMNS),
        'prc_estimate_items' => count(ITEM_COLUMNS),
        'prc_estimate_resources' => count(RESOURCE_COLUMNS),
        'prc_estimate_totals' => count(TOTAL_COLUMNS),
        'sum' => count(ESTIMATE_COLUMNS) + count(SECTION_COLUMNS) + count(ITEM_COLUMNS) + count(RESOURCE_COLUMNS) + count(TOTAL_COLUMNS),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
