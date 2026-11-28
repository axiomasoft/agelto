<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\GoldSource;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Tuning\EvalRunComparator;

/**
 * `estimate:eval:compare` / `EvalRunComparator` и режим `noise-aware` проверяются на синтетических прогонах (Scope
 * Excluded — ни одного реального LLM-вызова); `EvalRun`/`EvalResult`/`Fixture`/`DatasetExample`
 * строятся фабриками, разбиение `tune`/`holdout` подкладывается временным файлом (config-override
 * `ai.fixtures.splits_path`). `EvalRunFactory` по умолчанию несёт `started_at`/`finished_at`
 * (`now()`) — профиль вызовов `noise-aware` их требует и в тестовой БД без `llm_calls` честно
 * считает нулевые агрегаты, не `RuntimeException`.
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
        'type_profile' => [
            'class' => 'est',
            'form' => 'KS2',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
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

test('artisan estimate:eval:compare отдаёт вердикт с exit 0 и повторный запуск даёт тот же вывод (Validation #5)', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-D-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-D-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.82);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.798);

    $first = $this->artisan('estimate:eval:compare', ['--base' => $base->id, '--candidate' => $candidate->id]);
    $first->assertExitCode(0);

    $second = $this->artisan('estimate:eval:compare', ['--base' => $base->id, '--candidate' => $candidate->id]);
    $second->assertExitCode(0);
});

test('нулевая дельта tune: patch даёт reject, no-regression — accept', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-E-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-E-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.80);

    $comparator = app(EvalRunComparator::class);

    $patchResult = $comparator->compare($base, $candidate, EvalRunComparator::CRITERION_PATCH);
    $noRegressionResult = $comparator->compare($base, $candidate, EvalRunComparator::CRITERION_NO_REGRESSION);

    expect($patchResult['verdict'])->toBe('reject')
        ->and($patchResult['reasons'][0])->toContain('(а)')
        ->and($noRegressionResult['verdict'])->toBe('accept')
        ->and($noRegressionResult['reasons'])->toBe([]);
});

test('падение holdout сверх порога даёт reject в обоих режимах', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-F-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-F-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.82);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.79);

    $comparator = app(EvalRunComparator::class);

    $patchResult = $comparator->compare($base, $candidate, EvalRunComparator::CRITERION_PATCH);
    $noRegressionResult = $comparator->compare($base, $candidate, EvalRunComparator::CRITERION_NO_REGRESSION);

    expect($patchResult['verdict'])->toBe('reject')
        ->and($patchResult['reasons'][0])->toContain('(б)')
        ->and($noRegressionResult['verdict'])->toBe('reject')
        ->and($noRegressionResult['reasons'][0])->toContain('(б)');
});

test('artisan estimate:eval:compare без --criterion ведёт себя как раньше (дефолт patch)', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-G-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-G-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.80);

    $withoutCriterion = $this->artisan('estimate:eval:compare', ['--base' => $base->id, '--candidate' => $candidate->id]);
    $withoutCriterion->assertExitCode(0);

    $withPatch = $this->artisan('estimate:eval:compare', ['--base' => $base->id, '--candidate' => $candidate->id, '--criterion' => 'patch']);
    $withPatch->assertExitCode(0);

    $result = app(EvalRunComparator::class)->compare($base, $candidate);

    expect($result['verdict'])->toBe('reject')
        ->and($result['reasons'][0])->toContain('(а)');
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

test('noise-aware: нулевая пара, сравнённая сама с собой, даёт accept', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-H-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-H-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $noiseBase = evalCompareRun();
    $noiseCandidate = evalCompareRun();

    evalCompareResult($noiseBase->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseBase->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseCandidate->id, $tune['dataset_example_id'], f1: 0.75, rowCoverage: 0.90);
    evalCompareResult($noiseCandidate->id, $holdout['dataset_example_id'], f1: 0.70, rowCoverage: 0.95);

    $result = app(EvalRunComparator::class)->compare(
        base: $noiseBase,
        candidate: $noiseCandidate,
        criterion: EvalRunComparator::CRITERION_NOISE_AWARE,
        noisePairBase: $noiseBase,
        noisePairCandidate: $noiseCandidate,
    );

    expect($result['verdict'])->toBe('accept')
        ->and($result['reasons'])->toBe([]);
});

test('noise-aware: дельта РОВНО на пороге шума — accept (граница включена)', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-I-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-I-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $noiseBase = evalCompareRun();
    $noiseCandidate = evalCompareRun();

    // Шум пары: holdout.f1 Δ=-0.05 (порог 0.05), tune.f1/row_coverage без изменений — per-fixture
    // порог тоже 0.05 (худший эталон — holdout).
    evalCompareResult($noiseBase->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseBase->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseCandidate->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseCandidate->id, $holdout['dataset_example_id'], f1: 0.75, rowCoverage: 1.0);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    // Сравниваемая пара повторяет РОВНО ту же величину падения holdout.f1, что и шум (-0.05).
    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.75, rowCoverage: 1.0);

    $result = app(EvalRunComparator::class)->compare(
        base: $base,
        candidate: $candidate,
        criterion: EvalRunComparator::CRITERION_NOISE_AWARE,
        noisePairBase: $noiseBase,
        noisePairCandidate: $noiseCandidate,
    );

    expect($result['verdict'])->toBe('accept')
        ->and($result['reasons'])->toBe([]);
});

test('noise-aware: дельта выше порога по одной оси — reject с ровно одной причиной', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-J-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-J-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $noiseBase = evalCompareRun();
    $noiseCandidate = evalCompareRun();

    // Шум пары: tune.row_coverage Δ=-0.05 (порог 0.05), f1 нигде не меняется (f1-пороги — 0.0).
    evalCompareResult($noiseBase->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseBase->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($noiseCandidate->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 0.95);
    evalCompareResult($noiseCandidate->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    // Сравниваемая пара: f1 везде идентичен (не даёт причин (б)/(г)), tune.row_coverage падает на
    // 0.10 — вдвое больше порога 0.05.
    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 0.90);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);

    $result = app(EvalRunComparator::class)->compare(
        base: $base,
        candidate: $candidate,
        criterion: EvalRunComparator::CRITERION_NOISE_AWARE,
        noisePairBase: $noiseBase,
        noisePairCandidate: $noiseCandidate,
    );

    expect($result['verdict'])->toBe('reject')
        ->and($result['reasons'])->toHaveCount(1)
        ->and($result['reasons'][0])->toContain('(в)')
        ->and($result['reasons'][0])->toContain('tune.row_coverage');
});

test('artisan estimate:eval:compare --criterion=noise-aware без --noise-pair завершается ошибкой, а не берёт дефолт', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-K-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-K-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.80);

    $result = $this->artisan('estimate:eval:compare', [
        '--base' => $base->id,
        '--candidate' => $candidate->id,
        '--criterion' => 'noise-aware',
    ]);

    $result->assertExitCode(1);
});

test('noise-aware: несравнимый состав эталонов нулевой пары — ошибка, а не тихий проход', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-L-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-L-001');
    $noiseOnlyBase = evalCompareSeedExample('EST-XLSX-NOISEBASE-L-001');
    $noiseOnlyCandidate = evalCompareSeedExample('EST-XLSX-NOISECAND-L-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.80);

    // Нулевая пара: базовый прогон несёт ОДИН эталон, кандидат — ДРУГОЙ, состав не совпадает.
    $noiseBase = evalCompareRun();
    $noiseCandidate = evalCompareRun();

    evalCompareResult($noiseBase->id, $noiseOnlyBase['dataset_example_id'], f1: 0.80);
    evalCompareResult($noiseCandidate->id, $noiseOnlyCandidate['dataset_example_id'], f1: 0.75);

    expect(fn () => app(EvalRunComparator::class)->compare(
        base: $base,
        candidate: $candidate,
        criterion: EvalRunComparator::CRITERION_NOISE_AWARE,
        noisePairBase: $noiseBase,
        noisePairCandidate: $noiseCandidate,
    ))->toThrow(RuntimeException::class);
});

test('no-regression на тех же данных, что noise-aware тесты, даёт прежний вердикт (защита от регрессии)', function () {
    $tune = evalCompareSeedExample('EST-XLSX-TUNE-M-001');
    $holdout = evalCompareSeedExample('EST-XLSX-HOLD-M-001');

    evalCompareWriteSplits(holdout: [$holdout['code']]);

    $base = evalCompareRun();
    $candidate = evalCompareRun();

    evalCompareResult($base->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($base->id, $holdout['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($candidate->id, $tune['dataset_example_id'], f1: 0.80, rowCoverage: 1.0);
    evalCompareResult($candidate->id, $holdout['dataset_example_id'], f1: 0.79, rowCoverage: 1.0); // -0.01

    $result = app(EvalRunComparator::class)->compare($base, $candidate, EvalRunComparator::CRITERION_NO_REGRESSION);

    expect($result['verdict'])->toBe('reject')
        ->and($result['reasons'])->toHaveCount(1)
        ->and($result['reasons'][0])->toContain('(б)')
        ->and($result)->not->toHaveKey('noise_basis')
        ->and($result)->not->toHaveKey('profile')
        ->and($result['subsamples'])->not->toHaveKey('overall');
});
