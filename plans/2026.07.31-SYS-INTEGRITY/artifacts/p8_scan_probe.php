<?php

declare(strict_types=1);

/**
 * P8.1 Scope Included п.3«з» (D151) — ЕДИНСТВЕННЫЙ платный прогон item'а. Зонд плана, НЕ код
 * приложения: резолв сервисов через app(...), процедурный скрипт под `artisan tinker --execute=`.
 * Повторяет дисциплину `FormatBenchRunner::runCell()` (Required Reads P8.1 п.9): сброс
 * `Analysis(step=extraction)` перед прогоном, граница вызовов по `LlmCall::max('id')` ДО цикла,
 * цикл до терминала. Пре-гейт `Whole` (Implementation Rules P8.1 п.1) исполняется САМИМ зондом
 * ДО цикла — на представлении из `RepresentationResolver::forLlm($version)`, не на исходном PDF.
 * Кап (Implementation Rules P8.1 п.2): 20 платных вызовов суммарно И 10 итераций оркестратора.
 */

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Estimate\ExtractionRoute;
use App\Enums\Procurement\Estimate\RowType;
use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Analyzers\Estimate\IncompleteRow;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Procurement\Analyzers\Estimate\EstimateStitcher;
use App\Services\Procurement\Analyzers\Estimate\EstimateValidator;
use App\Services\Procurement\Analyzers\Estimate\ExtractionOrchestrator;
use App\Services\Procurement\Extraction\ExtractionPreflight;
use App\Services\Procurement\Prepare\RepresentationResolver;
use Illuminate\Support\Facades\Storage;

const P8_PROBE_FIXTURE_CODE = 'EST-PDF-SCAN-002';
const P8_PROBE_CALL_CAP = 20;
const P8_PROBE_ITERATION_CAP = 10;

// Baseline `llm_calls.id` ДО первого запуска этого зонда за весь item P8.1 — `llm_calls` пуста на
// момент старта item'а (Validation п.6, снято тинкером до первого прогона: `LlmCall::latest('id')
// ->first()?->id` → null). Кап п.2 «20 вызовов суммарно» считается от ЭТОЙ точки на все попытки
// (включая ре-прогон после инфраструктурного провала), не от состояния на момент КАЖДОГО запуска.
const P8_PROBE_BASELINE_CALL_ID = 0;

// Та же база сравнения, что оффлайн-профиль п.2 (`p8_null_profile.py`, ROW_FIELDS) — числа
// обязаны быть сравнимы.
const P8_PROBE_ROW_FIELDS = [
    'section_title', 'code', 'unit', 'quantity', 'unit_price_base', 'unit_price_curr',
    'total_price_base', 'total_price_curr', 'index_code', 'index_value',
    'source_position_number', 'work_type_name', 'attributes',
];

function p8_probe_is_null(mixed $value): bool
{
    return $value === null || $value === [];
}

function p8_probe_null_rate(array $estimates): array
{
    $items = [];
    foreach ($estimates as $estimate) {
        foreach ((array) ($estimate['rows'] ?? []) as $row) {
            if (($row['row_type'] ?? null) === RowType::Item->value) {
                $items[] = $row;
            }
        }
    }

    $nullByField = array_fill_keys(P8_PROBE_ROW_FIELDS, 0);
    foreach ($items as $row) {
        foreach (P8_PROBE_ROW_FIELDS as $field) {
            if (p8_probe_is_null($row[$field] ?? null)) {
                $nullByField[$field]++;
            }
        }
    }

    $count = count($items);

    return [
        'items' => $count,
        'null_rate' => array_map(
            static fn (int $n): ?float => $count > 0 ? round($n / $count, 4) : null,
            $nullByField,
        ),
    ];
}

/**
 * Тот же вызов, что `EstimatePersister::persistEstimate()` делает внутри транзакции персиста
 * (`app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:94-95`) — но здесь ТОЛЬКО
 * читается отчёт, ничего не персистится второй раз. `EstimatePersister::persist()` уже отработал
 * (если прогон дошёл до `finalizeSuccess()`) и посчитал ровно этот же отчёт, но вернул наружу
 * только `validation_status` (находка §1 findings) — отчёт целиком иначе НИГДЕ не остаётся.
 */
function p8_probe_validator_reports(array $estimates, EstimateStitcher $stitcher, EstimateValidator $validator): array
{
    $reports = [];

    foreach ($estimates as $i => $estimate) {
        try {
            $rows = array_map(
                static fn (array $row): EstimateRow => EstimateRow::from($row),
                (array) ($estimate['rows'] ?? []),
            );
            $incompleteItems = array_map(
                static fn (array $row): IncompleteRow => IncompleteRow::from($row),
                (array) ($estimate['incomplete_items'] ?? []),
            );
            $totals = isset($estimate['totals']) && $estimate['totals'] !== null
                ? EstimateTotalsBlock::from($estimate['totals'])
                : null;

            $stitched = $stitcher->stitch($rows, $incompleteItems);
            $report = $validator->validate((int) ($estimate['rows_expected'] ?? 0), $stitched->rows, $totals);

            $reports[] = [
                'ordinal' => $estimate['ordinal'] ?? $i,
                'status' => $report->status->value,
                'anomalous_row_indexes' => $report->anomalousRowIndexes,
                'notes' => $report->notes,
                'dropped_duplicate_row_indexes' => $stitched->droppedDuplicateRowIndexes,
                'unmatched_incomplete_items' => count($stitched->unmatchedIncompleteItems),
            ];
        } catch (\Throwable $e) {
            $reports[] = [
                'ordinal' => $estimate['ordinal'] ?? $i,
                'error' => $e::class.': '.$e->getMessage(),
            ];
        }
    }

    return $reports;
}

echo "=== P8.1 п.3«з» — живая проба скана (".P8_PROBE_FIXTURE_CODE.") ===\n";

$fixture = Fixture::where('code', P8_PROBE_FIXTURE_CODE)->firstOrFail();
$document = $fixture->document;

if ($document === null) {
    throw new RuntimeException('Рабочая копия эталона не создана — шаг «г» (estimate:gold:import --prepare) не выполнен.');
}

$version = DocumentVersion::query()
    ->where('document_id', $document->id)
    ->where('content_hash', $fixture->sha256)
    ->firstOrFail();

$representationsBefore = DocumentRepresentation::query()
    ->where('document_version_id', $version->id)
    ->pluck('kind')
    ->map(static fn ($kind) => $kind->value)
    ->all();

echo 'document_id='.$document->id." document_status={$document->status->value} version_id={$version->id}\n";
echo 'representations (до пре-гейта): ['.implode(', ', $representationsBefore)."]\n";

$route = app(LlmRouteResolver::class)->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);
$effective = app(RepresentationResolver::class)->forLlm($version);

$artifact = [
    'fixture_code' => P8_PROBE_FIXTURE_CODE,
    'document_id' => $document->id,
    'document_version_id' => $version->id,
    'document_status_before' => $document->status->value,
    'representations_before' => $representationsBefore,
    'route' => ['provider' => $route->route->provider->code, 'model' => $route->options->model],
];

if ($effective === null) {
    $artifact['aborted'] = 'RepresentationResolver::forLlm() вернул null — нет ни представления по политике, ни фолбэка на оригинал.';
    echo "АБОРТ: {$artifact['aborted']}\n";
    file_put_contents(__DIR__.'/p8-scan-probe.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return;
}

$artifact['effective_representation'] = $effective;

$localPath = tempnam(sys_get_temp_dir(), 'p8_probe_').'.'.$effective['extension'];
file_put_contents($localPath, Storage::disk($effective['disk'])->get($effective['storagePath']));

try {
    $decision = app(ExtractionPreflight::class)->decide($localPath, $effective['extension'], $route);
} finally {
    @unlink($localPath);
}

$routeName = $decision->route?->name ?? 'null';
echo "пре-гейт: route={$routeName}".($decision->isUnprocessable() ? " unprocessable_reason={$decision->unprocessableReason->value}" : '')."\n";

$artifact['preflight_route'] = $routeName;
$artifact['preflight_unprocessable_reason'] = $decision->isUnprocessable() ? $decision->unprocessableReason->value : null;

if ($decision->route !== ExtractionRoute::Whole) {
    $artifact['aborted'] = "пре-гейт не Whole (route={$routeName}) — платный шаг НЕ запущен (Implementation Rules P8.1 п.1).";
    echo "АБОРТ: {$artifact['aborted']}\n";
    file_put_contents(__DIR__.'/p8-scan-probe.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return;
}

// Implementation Rules P8.1 п.2 — понижение checkpoint_ranges ДО цикла, поведение не подменяется,
// только чаще отдаёт зонду точку контроля между итерациями.
config(['ai.extraction.checkpoint_ranges' => 2]);
$artifact['checkpoint_ranges_override'] = (int) config('ai.extraction.checkpoint_ranges');

$analyses = app(AnalysisStoreRepository::class);
$runs = app(AnalysisRunStoreRepository::class);
$orchestrator = app(ExtractionOrchestrator::class);

$analysis = $analyses->firstOrCreate($version->id, AnalysisStep::Extraction);

// Резюмируемость (реальный сценарий: инфраструктурный провал ОДНОЙ из внутренних LLM-вызовов
// оборвал предыдущий запуск ЭТОГО скрипта, checkpoint-requeue уже персистировал прогресс в
// `analysis.progress` — Required Reads P8.1 п.9, тот же механизм, что боевой
// `EstimateExtractionJob`). Сброс на `Pending`/null — ТОЛЬКО на первом запуске (аналог свежей
// ячейки `FormatBenchRunner::runCell()`); застрявший `running` с уже накопленным прогрессом
// ПРОДОЛЖАЕТСЯ, а не переигрывается — иначе повторный сетевой таймаут выбрасывал бы уже
// оплаченный прогресс (calls/tokens/rows) на каждой попытке.
$isResume = $analysis->status === AnalysisStatus::Running && $analysis->progress !== null;

echo $isResume
    ? "РЕЗЮМЕ: предыдущий запуск остановлен на checkpoint (analysis.status=running), продолжаем без сброса.\n"
    : "СТАРТ: свежий сброс Analysis(step=extraction).\n";

// Активный (не терминальный) `AnalysisRun` предыдущей попытки блокирует создание нового
// (`prc_ai_analysis_runs_active_version_unique`) — закрываем его как `failed` ПЕРЕД стартом
// нового прогона, продолжение работы фиксируется НОВОЙ строкой прогона.
$staleRun = \App\Models\Procurement\Ai\AnalysisRun::query()
    ->where('document_version_id', $version->id)
    ->whereIn('status', [\App\Enums\Procurement\Ai\AnalysisRunStatus::Pending, \App\Enums\Procurement\Ai\AnalysisRunStatus::Running])
    ->first();

if ($staleRun !== null) {
    echo "закрываю зависший AnalysisRun #{$staleRun->id} (status={$staleRun->status->value}) как failed перед новым запуском\n";
    $runs->markFailed($staleRun);
}

$run = $runs->start($version->id, RunMode::Manual);

if ($isResume) {
    $analyses->update($analysis, ['analysis_run_id' => $run->id]);
} else {
    $analyses->update($analysis, [
        'status' => AnalysisStatus::Pending,
        'analysis_run_id' => $run->id,
        'document_type' => AiDocumentType::Estimate->value,
        'result' => null,
        'progress' => null,
        'provider' => null,
        'model' => null,
        'attempts' => 0,
        'last_error' => null,
        'last_error_at' => null,
        'next_retry_at' => null,
        'failed_permanently' => false,
        'started_at' => null,
        'finished_at' => null,
        'cache_id' => null,
        'cache_expires_at' => null,
    ]);
}
$analysis->refresh();

// Кап (Implementation Rules P8.1 п.2) считается СУММАРНО по item'у, не по запуску скрипта:
// баз(a)линия — состояние `llm_calls` ДО первого запуска зонда (подтверждено Validation п.6:
// таблица пуста), НЕ пересчитывается динамически на каждый `require` — иначе повторный запуск
// после инфраструктурного провала первого (Implementation Rules п.2, разрешённое исключение)
// молча получил бы вторые 20 вызовов вместо доедания бюджета первой попытки.
$maxLlmCallId = P8_PROBE_BASELINE_CALL_ID;
$iterations = 0;
$capped = false;
$capReason = null;
$infraError = null;

echo "=== цикл ExtractionOrchestrator::run() (кап: {".P8_PROBE_CALL_CAP." вызовов суммарно / ".P8_PROBE_ITERATION_CAP." итераций ЭТОГО запуска}) ===\n";

while (true) {
    if ($iterations >= P8_PROBE_ITERATION_CAP) {
        $capped = true;
        $capReason = 'iterations';
        break;
    }

    try {
        $needsContinuation = $orchestrator->run($document, $analysis);
    } catch (\Throwable $e) {
        // Инфраструктурный провал (таймаут/сеть) — разрешённое исключение Implementation Rules
        // п.2: не глотать молча, зафиксировать факт и завершить ЭТОТ запуск, не теряя уже
        // накопленный расход (`llm_calls` останется как есть, ре-прогон досчитает бюджет).
        $infraError = $e::class.': '.$e->getMessage();
        $capped = true;
        $capReason = 'infra_error';
        echo "ИНФРАСТРУКТУРНЫЙ ПРОВАЛ: {$infraError}\n";
        break;
    }

    $analysis->refresh();
    $iterations++;

    $callsSoFar = LlmCall::query()->where('id', '>', $maxLlmCallId)->count();
    $callsAgg = LlmCall::query()->where('id', '>', $maxLlmCallId)->selectRaw(
        'COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(cost_micros),0) as cost_micros'
    )->first();

    echo "итерация {$iterations}: analysis.status={$analysis->status->value} вызовов_суммарно={$callsSoFar} токенов_суммарно={$callsAgg->tokens} cost_micros_суммарно={$callsAgg->cost_micros}\n";

    if ($callsSoFar >= P8_PROBE_CALL_CAP) {
        $capped = true;
        $capReason = 'calls';
        break;
    }

    if (! $needsContinuation) {
        break;
    }
}

$run->refresh();
$analysis->refresh();
$document->refresh();

match ($analysis->status) {
    AnalysisStatus::Succeeded, AnalysisStatus::Skipped => $runs->markSucceeded($run),
    default => $runs->markFailed($run),
};

$calls = LlmCall::query()->where('id', '>', $maxLlmCallId)->get();

$representationsAfter = DocumentRepresentation::query()
    ->where('document_version_id', $version->id)
    ->pluck('kind')
    ->map(static fn ($kind) => $kind->value)
    ->all();

// `result`/`progress` кастуются `AsCollection` (`App\Models\Procurement\Ai\Analysis`) — верхний
// уровень `Collection`, вложенные структуры остаются обычными PHP-массивами; `(array)`-кастинг
// объекта `Collection` НЕ равносилен `->toArray()` (кастует свойства объекта, не содержимое).
$rawResult = $analysis->result?->toArray() ?? [];
$rawProgress = $analysis->progress?->toArray() ?? [];
$estimates = (array) ($rawResult['estimates'] ?? ($rawProgress['estimates'] ?? []));

$artifact += [
    'is_resume' => $isResume,
    'capped' => $capped,
    'cap_reason' => $capReason,
    'infra_error' => $infraError,
    'iterations' => $iterations,
    'document_status_after' => $document->status->value,
    'document_last_error' => $document->last_error,
    'analysis_status' => $analysis->status->value,
    'representations_after' => $representationsAfter,
    'usage' => [
        'calls' => $calls->count(),
        'failed_calls' => $calls->where('success', false)->count(),
        'prompt_tokens' => (int) $calls->sum('prompt_tokens'),
        'cached_tokens' => (int) $calls->sum('cached_tokens'),
        'completion_tokens' => (int) $calls->sum('completion_tokens'),
        'total_tokens' => (int) $calls->sum('total_tokens'),
        'cost_micros' => (int) $calls->sum('cost_micros'),
        'duration_ms' => (int) $calls->sum('duration_ms'),
    ],
    'estimates_count' => count($estimates),
    'null_rate' => p8_probe_null_rate($estimates),
    'validator_reports' => p8_probe_validator_reports($estimates, app(EstimateStitcher::class), app(EstimateValidator::class)),
    // Сырой ответ ЦЕЛИКОМ (Code Guidance P8.1: обрезка «для читаемости» запрещена) — единственный
    // носитель того, что модель реально вернула на нечитаемом источнике.
    'raw_result' => $rawResult !== [] ? $rawResult : null,
    'raw_progress' => $rawProgress !== [] ? $rawProgress : null,
];

file_put_contents(__DIR__.'/p8-scan-probe.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "=== итог: analysis.status={$artifact['analysis_status']} capped=".($capped ? "да ({$capReason})" : 'нет')." строк={$artifact['null_rate']['items']} вызовов={$artifact['usage']['calls']} cost_micros={$artifact['usage']['cost_micros']} ===\n";
echo "артефакт: ".__DIR__."/p8-scan-probe.json\n";
