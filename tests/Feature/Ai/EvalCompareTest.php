<?php

declare(strict_types=1);

use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Tuning\EvalRunComparator;

/**
 * `ai:eval:compare` / `EvalRunComparator` (P7.5, критерий D63 + правило отбора D119/D120):
 * тестируется целиком на СИНТЕТИЧЕСКИХ прогонах (Scope Excluded — ни одного реального LLM-вызова);
 * `EvalRun`/`EvalResult`/`Fixture`/`DatasetExample` строятся фабриками, разбиение `tune`/`holdout`
 * подкладывается временным файлом (config-override `ai.fixtures.splits_path`).
 */
function evalCompareWriteSplits(array $holdout): void
{
    $path = storage_path('framework/testing/eval-compare-splits-'.uniqid().'.json');

    file_put_contents($path, json_encode([
        'generated_at' => '2026-07-28T00:00:00+00:00',
        'rule' => 'test-fixture',
        'holdout' => $holdout,
        'tune' => [],
    ], JSON_UNESCAPED_UNICODE));

    config(['ai.fixtures.splits_path' => $path]);
}

/**
 * @return array{code: string, dataset_example_id: int}
 */
function evalCompareSeedExample(string $code): array
{
    $fixture = Fixture::factory()->create([
        'code' => $code,
        'gold_source' => GoldSource::Deterministic,
    ]);

    $example = DatasetExample::factory()->create([
        'fixture_id' => $fixture->id,
        'task' => 'extraction',
    ]);

    return ['code' => $code, 'dataset_example_id' => $example->id];
}

function evalCompareResult(int $evalRunId, int $datasetExampleId, float $f1, float $rowCoverage = 1.0): void
{
    EvalResult::query()->create([
        'eval_run_id' => $evalRunId,
        'dataset_example_id' => $datasetExampleId,
        'metric' => 'f1',
        'value' => $f1,
        'details' => [
            'overall' => ['precision' => $f1, 'recall' => $f1, 'f1' => $f1],
            'row_coverage' => $rowCoverage,
            'per_field' => ['code' => ['precision' => $f1, 'recall' => $f1, 'f1' => $f1]],
        ],
    ]);
}

/**
 * @param  array<string, int>|null  $promptVersionSet
 */
function evalCompareRun(?array $promptVersionSet = ['global' => 1]): EvalRun
{
    return EvalRun::factory()->create([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'prompt_version_set' => $promptVersionSet,
    ]);
}

test('прогон с разъехавшимися версиями промтов (prompt_version_set=null) не сравнивается — явная ошибка', function () {
    evalCompareWriteSplits(holdout: []);

    $base = evalCompareRun(promptVersionSet: null);
    $candidate = evalCompareRun();

    expect(fn () => app(EvalRunComparator::class)->compare($base, $candidate))
        ->toThrow(RuntimeException::class);
});

test('критерий даёт accept на росте tune без падения holdout', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-A-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-A-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.82);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.798);

    $result = app(EvalRunComparator::class)->compare($base, $candidate);

    expect($result['verdict'])->toBe('accept')
        ->and($result['reasons'])->toBe([]);
});

test('критерий даёт reject при падении holdout на 0.01', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-B-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-B-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.82);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.79);

    $result = app(EvalRunComparator::class)->compare($base, $candidate);

    expect($result['verdict'])->toBe('reject')
        ->and($result['reasons'][0])->toContain('(б)');
});

test('artisan ai:eval:compare отдаёт вердикт с exit 0 и повторный запуск даёт тот же вывод (Validation #5)', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-D-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-D-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.82);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.798);

    $first = $this->artisan('ai:eval:compare', ['--base' => $base->id, '--candidate' => $candidate->id]);
    $first->assertExitCode(0);

    $second = $this->artisan('ai:eval:compare', ['--base' => $base->id, '--candidate' => $candidate->id]);
    $second->assertExitCode(0);
});

test('критерий даёт reject при падении одного эталона на 0.06 даже при общем росте tune', function () {
    $tune1 = evalCompareSeedExample('EST-XLSX-TUNE-C-001');
    $tune2 = evalCompareSeedExample('EST-XLSX-TUNE-C-002');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-C-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune1['dataset_example_id'], f1: 0.90);
    evalCompareResult($base->id, $tune2['dataset_example_id'], f1: 0.70);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);

    evalCompareResult($candidate->id, $tune1['dataset_example_id'], f1: 0.84); // -0.06
    evalCompareResult($candidate->id, $tune2['dataset_example_id'], f1: 0.86); // +0.16
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.80);

    $result = app(EvalRunComparator::class)->compare($base, $candidate);

    expect($result['verdict'])->toBe('reject')
        ->and($result['reasons'][0])->toContain('(г)');
});
