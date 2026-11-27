<?php

declare(strict_types=1);

use App\Actions\Procurement\Ai\ReviewAnalysisAction;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\CorrectionReason;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\Ai\DatasetExampleReadRepository;
use App\Services\Procurement\Analyzers\Estimate\Eval\DatasetExporter;
use App\Services\Procurement\Analyzers\Estimate\Eval\EvalMetrics;
use App\Services\Procurement\Tuning\EvalRunComparator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\ProviderReportFixtures;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(function (): void {
    config(['ai.fixtures.splits_path' => storage_path('framework/testing/'.Str::uuid().'.json')]);
});

afterEach(fn (): bool => File::delete((string) config('ai.fixtures.splits_path')));

test('экспорт выбранной подвыборки прерывается при отсутствии файла разбиения', function (string $format, string $split): void {
    expect(fn () => app(DatasetExporter::class)->export(
        format: $format,
        task: 'extraction',
        split: $split,
        sources: [],
        dpoSchema: 'standard',
    ))->toThrow(RuntimeException::class, "Для --split={$split}");
})->with(['sft', 'dpo'])->with(['tune', 'holdout']);

test('общий экспорт без файла разбиения помечает фикстуру как неразмеченную', function (): void {
    Storage::fake('s3');
    $example = DatasetExample::factory()->create([
        'fixture_id' => Fixture::factory(),
        'task' => 'extraction',
        'source' => 'deterministic',
        'gold' => ['estimates' => [['rows' => [], 'incomplete_items' => []]]],
    ]);
    $content = 'Текст для экспорта';
    $path = 'documents/estimate.txt';
    Storage::disk('s3')->put($path, $content);
    DocumentRepresentation::factory()->create([
        'document_version_id' => $example->document_version_id,
        'kind' => RepresentationKind::Text,
        'storage_path' => $path,
        'disk' => 's3',
        'content_hash' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'mime' => 'text/plain',
    ]);

    [$stats, $stream] = app(DatasetExporter::class)->export(
        format: 'sft',
        task: 'extraction',
        split: 'all',
        sources: [],
        dpoSchema: 'standard',
    );
    try {
        $row = json_decode(stream_get_contents($stream), associative: true, flags: JSON_THROW_ON_ERROR);
        expect($stats['by_split'])->toBe(['none' => 1])
            ->and($stats['warnings'][0])->toContain('split=none')
            ->and($row['meta']['fixture_code'])->toBe($example->fixture->code)
            ->and($row['meta']['split'])->toBe('none');
    } finally {
        fclose($stream);
    }
});

test('отклонение снимает допуск gold к экспорту, а новое одобрение восстанавливает допуск того же снимка', function (): void {
    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Extraction, 'document_type' => 'estimate']);
    Estimate::factory()->create(['analysis_id' => $analysis->id, 'document_version_id' => $analysis->document_version_id]);
    $firstReviewer = User::factory()->create();
    $secondReviewer = User::factory()->create();
    $review = app(ReviewAnalysisAction::class);
    $examples = app(DatasetExampleReadRepository::class);

    expect($review->approve(analysisId: $analysis->id, reviewerId: $firstReviewer->id))->toBeTrue();
    $snapshot = DatasetExample::query()->where('analysis_id', $analysis->id)->sole();
    expect($snapshot->verified_by)->toBe($firstReviewer->id)
        ->and($snapshot->verified_at)->not->toBeNull()
        ->and($examples->exportableSft(task: 'extraction', sources: ['llm'])->pluck('id')->all())->toBe([$snapshot->id]);

    expect($review->rejectWithCorrection(
        analysisId: $analysis->id,
        reviewerId: $secondReviewer->id,
        correction: ['reason' => CorrectionReason::WrongValue->value, 'content' => 'Проверить сумму'],
    ))->toBeTrue();
    $revoked = $snapshot->fresh();
    expect($analysis->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($revoked->verified_by)->toBeNull()
        ->and($revoked->verified_at)->toBeNull()
        ->and($examples->exportableSft(task: 'extraction', sources: ['llm'])->isEmpty())->toBeTrue();

    $this->travel(1)->minutes();
    expect($review->approve(analysisId: $analysis->id, reviewerId: $secondReviewer->id))->toBeTrue()
        ->and($review->approve(analysisId: $analysis->id, reviewerId: $firstReviewer->id))->toBeFalse();
    $restored = DatasetExample::query()->where('analysis_id', $analysis->id)->sole();
    expect($restored->id)->toBe($snapshot->id)
        ->and($restored->gold?->all())->toBe($snapshot->gold?->all())
        ->and($restored->verified_by)->toBe($secondReviewer->id)
        ->and($restored->verified_at->greaterThan($snapshot->verified_at))->toBeTrue()
        ->and($examples->exportableSft(task: 'extraction', sources: ['llm'])->pluck('id')->all())->toBe([$snapshot->id]);
});

test('коррекция не записывается для анализа без успешного результата', function (): void {
    $analysis = Analysis::factory()->create([
        'status' => AnalysisStatus::Skipped,
        'step' => AnalysisStep::Extraction,
        'document_type' => 'estimate',
    ]);
    $reviewer = User::factory()->create();

    expect(app(ReviewAnalysisAction::class)->rejectWithCorrection(
        analysisId: $analysis->id,
        reviewerId: $reviewer->id,
        correction: ['reason' => CorrectionReason::WrongValue->value, 'content' => 'Проверить сумму'],
    ))->toBeFalse()
        ->and(Correction::query()->where('analysis_id', $analysis->id)->exists())->toBeFalse()
        ->and($analysis->refresh()->review_status)->toBe(ReviewStatus::Unreviewed);
});

test('повторяющиеся номера строк и вложенные итоги учитываются в метриках', function (): void {
    $firstRow = ['row_index' => 2, 'name' => 'Первая строка'];
    $lastRow = ['row_index' => 2, 'name' => 'Вторая строка'];
    $lastTotal = ['label' => 'Итого', 'kind' => 'subtotal', 'value' => '20'];
    $firstTotal = ['label' => ' ИТОГО ', 'kind' => 'subtotal', 'value' => '10', 'children' => [$lastTotal]];
    $gold = [['rows' => [$firstRow, $lastRow], 'totals' => ['footer' => [$firstTotal]]]];
    $prediction = [['rows' => [$lastRow], 'totals' => ['footer' => [$lastTotal]]]];

    $metrics = (new EvalMetrics)->compare(goldEstimates: $gold, predictedEstimates: $prediction);

    expect($metrics['per_field']['name']['recall'])->toBeLessThan(1.0)
        ->and($metrics['totals']['value']['recall'])->toBeLessThan(1.0)
        ->and($metrics['totals']['kind']['recall'])->toBe(0.5);
});

test('профиль оценки учитывает только расходы своего прогона при пересечении интервалов', function (): void {
    ProviderReportFixtures::reset();
    $base = integrityEvalRun();
    $candidate = integrityEvalRun();
    integrityEvalResults([$base, $candidate]);

    $call = ['provider' => $base->provider, 'model' => $base->model, 'called_at' => now(), 'cost_micros' => 1000, 'prompt_tokens' => 100];
    ProviderReportFixtures::store([...$call, 'consumer_correlation_id' => "eval-run:{$base->id}", 'completion_tokens' => 40]);
    ProviderReportFixtures::store([...$call, 'consumer_correlation_id' => "eval-run:{$candidate->id}", 'completion_tokens' => 25]);
    ProviderReportFixtures::store([...$call, 'completion_tokens' => 900]);

    $comparison = app(EvalRunComparator::class)->compare(
        base: $base,
        candidate: $candidate,
        criterion: EvalRunComparator::CRITERION_NOISE_AWARE,
        noisePairBase: $base,
        noisePairCandidate: $candidate,
    );

    expect($comparison['profile']['base'])->toBe([
        'calls' => 1, 'prompt_tokens' => 100, 'completion_tokens' => 40, 'avg_prompt_tokens' => 100.0, 'cost_micros' => 1000,
    ])->and($comparison['profile']['candidate']['calls'])->toBe(1)
        ->and($comparison['profile']['output_drop'])->toBe(15)
        ->and($comparison['profile']['output_drop_noise_threshold'])->toBe(15);
});

test('сравнение отклоняет нулевую пару с несовместимыми условиями', function (string $difference, string $error): void {
    $base = integrityEvalRun();
    $candidate = integrityEvalRun();
    $noiseOverrides = match ($difference) {
        'маршрут' => ['model' => 'другая-модель'],
        'задача' => ['task' => 'screening'],
        default => [],
    };
    $noiseBase = integrityEvalRun($noiseOverrides);
    $noiseCandidate = integrityEvalRun($difference === 'промпт' ? ['prompt_version_set' => ['global' => 2]] : $noiseOverrides);
    integrityEvalResults(
        runs: [$base, $candidate, $noiseBase, $noiseCandidate],
        differentNoiseExample: $difference === 'версия эталона',
    );

    expect(fn () => app(EvalRunComparator::class)->compare(
        base: $base,
        candidate: $candidate,
        criterion: EvalRunComparator::CRITERION_NOISE_AWARE,
        noisePairBase: $noiseBase,
        noisePairCandidate: $noiseCandidate,
    ))->toThrow(RuntimeException::class, $error);
})->with([
    ['маршрут', 'разный маршрут моделей'],
    ['задача', 'одинаковую задачу и тип документа'],
    ['промпт', 'одинаковый prompt_version_set'],
    ['версия эталона', 'Состав эталонов нулевой пары должен совпадать'],
]);

test('импорт подтверждает gold только при явном указании флага проверки', function (): void {
    StoredDocumentFixtures::reset();
    Storage::fake('s3');
    Platform::query()->firstOrCreate(['code' => Platform::TRAINING_CODE], Platform::factory()->training()->raw());
    $folder = storage_path('framework/testing/import-'.Str::uuid());
    File::ensureDirectoryExists($folder);
    try {
        File::put("{$folder}/example.pdf", 'PDF-DUMMY-CONTENT');
        File::put("{$folder}/example.gold.json", json_encode([
            'estimates' => [[
                'estimate_number' => '1',
                'passport' => ['type' => 'local_estimate'],
                'currency' => 'RUB',
                'rows' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('estimate:dataset:import', ['path' => $folder, '--verified' => true])->assertSuccessful();
        $example = DatasetExample::query()->sole();
        expect($example->verified_at)->not->toBeNull()->and($example->verified_by)->toBeNull();

        $this->artisan('estimate:dataset:import', ['path' => $folder])
            ->expectsOutput('Импорт без --verified сохраняет эталоны без отметки проверки.')
            ->assertSuccessful();
        expect(DatasetExample::query()->sole()->id)->toBe($example->id)
            ->and($example->refresh()->verified_at)->toBeNull();
    } finally {
        File::deleteDirectory($folder);
    }
});

/** @param array<string, mixed> $overrides */
function integrityEvalRun(array $overrides = []): EvalRun
{
    return EvalRun::factory()->create([
        'prompt_version_set' => ['global' => 1],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->addMinute(),
        ...$overrides,
    ]);
}

/** @param list<EvalRun> $runs */
function integrityEvalResults(array $runs, bool $differentNoiseExample = false): void
{
    $holdout = Fixture::factory()->create();
    $holdoutExample = DatasetExample::factory()->create(['fixture_id' => $holdout->id]);
    $tune = Fixture::factory()->create();
    $tuneExample = DatasetExample::factory()->create(['fixture_id' => $tune->id]);
    $noiseTuneExample = $differentNoiseExample
        ? DatasetExample::factory()->create([
            'fixture_id' => $tune->id,
            'document_version_id' => $tuneExample->document_version_id,
            'version' => 2,
        ])
        : $tuneExample;

    File::put((string) config('ai.fixtures.splits_path'), json_encode(['holdout' => [$holdout->code]], JSON_THROW_ON_ERROR));

    foreach ($runs as $index => $run) {
        foreach ([$holdoutExample, $index < 2 ? $tuneExample : $noiseTuneExample] as $example) {
            EvalResult::factory()->create([
                'eval_run_id' => $run->id,
                'dataset_example_id' => $example->id,
                'metric' => 'f1',
                'value' => 1.0,
                'details' => ['row_coverage' => 1.0, 'per_field' => ['name' => ['f1' => 1.0]]],
            ]);
        }
    }
}
