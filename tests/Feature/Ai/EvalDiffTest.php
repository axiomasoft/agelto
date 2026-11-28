<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Fixture\FixtureCaseResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * команда `estimate:eval:diff` (Implementation Rule 8): непокрытая на `--filter=EvalDiff`
 * до этого item'а (`EstimateDiffReportTest` бьёт по сервису, не по команде). Резолв через
 * {@see FixtureCaseResolver}, без единого LLM-вызова.
 */
function evalDiffGoldDir(): string
{
    $folder = sys_get_temp_dir().'/eval-diff-'.Str::uuid();
    File::ensureDirectoryExists($folder);
    config(['ai.fixtures.gold_dir' => $folder]);

    return $folder;
}

test('artisan(estimate:eval:diff) по --code печатает JSON-отчёт и вердикт', function (): void {
    $goldDir = evalDiffGoldDir();

    $fixture = Fixture::factory()->create(['code' => 'EST-DIFF-001', 'sha256' => hash('sha256', 'diff-content')]);
    $document = Document::factory()->create(['procurement_id' => null, 'external_id' => 'fixture:EST-DIFF-001']);
    $fixture->update(['document_id' => $document->id]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'content_hash' => $fixture->sha256]);

    $estimates = [['ordinal' => 1, 'rows' => [['row_index' => 1, 'row_type' => 'item', 'name' => 'Позиция', 'total_price_curr' => '100.00']]]];

    file_put_contents("{$goldDir}/EST-DIFF-001.gold.json", json_encode(['estimates' => $estimates], JSON_THROW_ON_ERROR));

    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'document_type' => 'estimate',
        'result' => ['estimates' => $estimates],
    ]);

    $this->artisan('estimate:eval:diff', ['--code' => 'EST-DIFF-001'])
        ->expectsOutputToContain('расхождений нет')
        ->assertExitCode(0);

    File::deleteDirectory($goldDir);
});

test('artisan(estimate:eval:diff) без --code и --analysis падает с понятной ошибкой', function (): void {
    $this->artisan('estimate:eval:diff')
        ->expectsOutputToContain('Нужен --code=<эталон> или --analysis=<id>.')
        ->assertExitCode(1);
});

test('artisan(estimate:eval:diff) по неизвестному коду эталона падает с сообщением резолвера', function (): void {
    evalDiffGoldDir();

    $this->artisan('estimate:eval:diff', ['--code' => 'EST-DIFF-UNKNOWN'])
        ->expectsOutputToContain('estimate:eval:run')
        ->assertExitCode(1);
});
