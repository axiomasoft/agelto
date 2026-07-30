<?php

declare(strict_types=1);

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisRunStatus;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\RunMode;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers\RunsRelationManager;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Schemas\Components\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Analyzer\TechSpecFakeAnalyzer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
 * Модалка результата прогона (P5.4, D27/D48): вердикт первичного анализа, сводка ОТ АНАЛИЗАТОРА
 * типа и эталон рядом. Тип в тестах — фиктивный `TechSpec` (P2.6): витрина обязана показывать
 * результат ЛЮБОГО типа, ничего не зная про предметку.
 *
 * Содержимое модалки проверяется по СХЕМЕ смонтированного действия, а не по HTML компонента:
 * модалки Filament рендерятся страницей-хостом, поэтому в изолированном рендере relation-менеджера
 * их разметки нет вовсе — HTML-ассерт был бы ложно-красным, а `assertDontSee` по нему — ложно-
 * зелёным. Вёрстка generic-рендера проверяется отдельным прогоном самой вьюхи (последний тест).
 */

// `artisan()` без потребления возврата откладывает выполнение до __destruct() — форсируем
// синхронный прогон явным assertExitCode (тот же приём, что FixtureRunHistoryTest).
beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

function actAsRunOperator(): void
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
}

/**
 * Регистрация фиктивного анализатора ОДНОЙ строкой конфига — тот же приём, что доказывает
 * расширяемость в `SecondDocumentTypeTest` (D14): реестр пересобирается на следующем резолве.
 */
function registerFakeAnalyzerForResultModal(): void
{
    config(['ai.analyzers' => [AiDocumentType::TechSpec->value => TechSpecFakeAnalyzer::class]]);
    app()->forgetInstance(DocumentAnalyzerRegistry::class);
}

/**
 * @return array{0: Fixture, 1: AnalysisRun, 2: DocumentVersion}
 */
function seedFixtureRunForResult(array $runAttributes = []): array
{
    $document = Document::factory()->create();
    $fixture = Fixture::factory()->est()->create(['document_id' => $document->id]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $run = AnalysisRun::factory()->create([
        'mode' => RunMode::Manual,
        'status' => AnalysisRunStatus::Succeeded,
        'finished_at' => now(),
        ...$runAttributes,
        'document_version_id' => $version->id,
    ]);

    return [$fixture, $run, $version];
}

function seedScreeningAnalysis(AnalysisRun $run, DocumentVersion $version, array $result = []): Analysis
{
    return Analysis::factory()->create([
        'analysis_run_id' => $run->id,
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::TechSpec->value,
        'result' => [
            'confidence' => 0.93,
            'description' => 'Похоже на техническое задание с таблицей объёмов',
            'tags' => ['таблица', 'объёмы'],
            ...$result,
        ],
    ]);
}

function seedExtractionAnalysis(AnalysisRun $run, DocumentVersion $version, AnalysisStatus $status = AnalysisStatus::Succeeded): Analysis
{
    return Analysis::factory()->create([
        'analysis_run_id' => $run->id,
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => $status,
        'document_type' => AiDocumentType::TechSpec->value,
    ]);
}

function mountResultModal(Fixture $fixture, AnalysisRun $run): Testable
{
    return Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->mountTableAction('result', record: $run);
}

/**
 * Состояние компонента модалки — то, что уходит в вёрстку.
 */
function modalState(Testable $modal, string $key): mixed
{
    $state = null;

    $modal->assertSchemaComponentExists($key, checkComponentUsing: function (Component $component) use (&$state): bool {
        $state = $component->getState();

        return true;
    });

    return $state;
}

test('модалка показывает вердикт первичного анализа и заметки модели', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version, ['branch_reason' => 'ignore_rule']);

    mountResultModal($fixture, $run)
        ->assertSchemaComponentStateSet('screening_status', AnalysisStatus::Succeeded)
        ->assertSchemaComponentStateSet('screening_document_type', AiDocumentType::TechSpec->value)
        ->assertSchemaComponentStateSet('screening_confidence', '0.93')
        ->assertSchemaComponentStateSet('screening_description', 'Похоже на техническое задание с таблицей объёмов')
        ->assertSchemaComponentStateSet('screening_tags', 'таблица, объёмы')
        ->assertSchemaComponentStateSet('screening_branch', 'ignore_rule');
});

test('сводка разбора приходит от анализатора типа, а не из предметных таблиц', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    // Заголовок и метрика приходят ИЗ `TechSpecFakeAnalyzer::summarize()` — витрина их не сочиняет.
    $summary = modalState(mountResultModal($fixture, $run), 'summary');

    expect($summary['title'])->toBe('Фиктивный второй тип')
        ->and($summary['metrics'])->toBe([['label' => 'Позиции', 'value' => '0', 'unit' => null]])
        ->and($summary)->not->toHaveKey('message');
});

test('без зарегистрированного анализатора модалка честно говорит, что сводку взять не у кого', function (): void {
    actAsRunOperator();
    config(['ai.analyzers' => []]);
    app()->forgetInstance(DocumentAnalyzerRegistry::class);

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    $summary = modalState(mountResultModal($fixture, $run), 'summary');

    expect($summary['message'])->toContain('анализатор не зарегистрирован')
        ->and($summary)->not->toHaveKey('title');
});

test('эталон показан рядом со сводкой', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    DatasetExample::factory()->create([
        'fixture_id' => $fixture->id,
        'document_version_id' => $version->id,
        'task' => AnalysisStep::Extraction->value,
        'document_type' => AiDocumentType::TechSpec->value,
        'gold' => ['tier' => 1, 'marker' => 'ЭТАЛОННОЕ-ЗНАЧЕНИЕ'],
        'source' => 'deterministic',
        'version' => 2,
    ]);

    $modal = mountResultModal($fixture, $run);

    $modal->assertSchemaComponentStateSet('gold_task', AnalysisStep::Extraction->value)
        ->assertSchemaComponentStateSet('gold_version', 2)
        ->assertSchemaComponentStateSet('gold_source', 'deterministic')
        ->assertSchemaComponentHidden('gold_absent');

    expect(modalState($modal, 'gold_body'))->toContain('ЭТАЛОННОЕ-ЗНАЧЕНИЕ');
});

test('показана ПОСЛЕДНЯЯ версия эталона по задаче', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    foreach ([1, 3, 2] as $goldVersion) {
        DatasetExample::factory()->create([
            'fixture_id' => $fixture->id,
            'document_version_id' => $version->id,
            'task' => AnalysisStep::Extraction->value,
            'document_type' => AiDocumentType::TechSpec->value,
            'gold' => ['marker' => "версия-{$goldVersion}"],
            'version' => $goldVersion,
        ]);
    }

    $modal = mountResultModal($fixture, $run);

    $modal->assertSchemaComponentStateSet('gold_version', 3);

    expect(modalState($modal, 'gold_body'))->toContain('версия-3');
});

test('большой эталон обрезается, и факт обрезания виден', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    DatasetExample::factory()->create([
        'fixture_id' => $fixture->id,
        'document_version_id' => $version->id,
        'task' => AnalysisStep::Extraction->value,
        'document_type' => AiDocumentType::TechSpec->value,
        'gold' => ['rows' => range(1, 200)],
        'version' => 1,
    ]);

    $body = (string) modalState(mountResultModal($fixture, $run), 'gold_body');

    expect(substr_count($body, "\n"))->toBeLessThan(45)
        ->and($body)->toContain('показаны первые 40 строк из');
});

test('без эталона модалка говорит, чем он заводится, а не показывает пустой блок', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    $modal = mountResultModal($fixture, $run);

    $modal->assertSchemaComponentVisible('gold_absent')
        ->assertSchemaComponentHidden('gold_body');

    expect(modalState($modal, 'gold_absent'))
        ->toContain('Эталона нет')
        ->toContain('estimate:gold:import');
});

test('у незавершённого прогона результата нет, а есть честное «прогон ещё идёт»', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult([
        'status' => AnalysisRunStatus::Running,
        'finished_at' => null,
    ]);
    seedScreeningAnalysis($run, $version);

    $summary = modalState(mountResultModal($fixture, $run), 'summary');

    expect($summary['message'])->toContain('Прогон ещё идёт')
        ->and($summary)->not->toHaveKey('title');
});

test('завершённый прогон без шага разбора объясняет, почему разбора нет', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);

    $summary = modalState(mountResultModal($fixture, $run), 'summary');

    expect($summary['message'])->toContain('Шаг разбора не выполнялся');
});

test('отказавший шаг разбора показывает статус и ошибку, а не пустоту', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult();
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version, AnalysisStatus::Failed)
        ->update(['last_error' => 'провайдер вернул 429']);

    $summary = modalState(mountResultModal($fixture, $run), 'summary');

    expect($summary['message'])->toContain('Разбор не завершён')
        ->toContain('провайдер вернул 429');
});

test('токенов и цены в модалке нет — они живут в строке прогона', function (): void {
    actAsRunOperator();
    registerFakeAnalyzerForResultModal();

    [$fixture, $run, $version] = seedFixtureRunForResult([
        'prompt_tokens' => 1000,
        'cached_tokens' => 200,
        'completion_tokens' => 300,
        'total_tokens' => 1300,
        'cost_micros' => 45_600,
    ]);
    seedScreeningAnalysis($run, $version);
    seedExtractionAnalysis($run, $version);

    // Положительная проверка ПЕРЕД отрицательными: без неё «компонента нет» зеленело бы и при
    // неверной схеме ключей, то есть гейт не имел бы красного исхода.
    mountResultModal($fixture, $run)
        ->assertSchemaComponentExists('summary')
        ->assertSchemaComponentDoesNotExist('prompt_tokens')
        ->assertSchemaComponentDoesNotExist('cached_tokens')
        ->assertSchemaComponentDoesNotExist('completion_tokens')
        ->assertSchemaComponentDoesNotExist('total_tokens')
        ->assertSchemaComponentDoesNotExist('cost_micros');
});

test('generic-рендер сводки печатает заголовок, пары «подпись → значение» и факт обрезания', function (): void {
    $metrics = array_map(
        static fn (int $index): array => ['label' => "Метрика {$index}", 'value' => (string) $index, 'unit' => 'шт'],
        range(1, 20),
    );

    $html = view('filament.resources.fixtures.run-result', [
        'getState' => fn (): array => [
            'title' => 'Заголовок от анализатора',
            'metrics' => $metrics,
            'total' => 25,
            'hidden' => 5,
            'url' => null,
        ],
    ])->render();

    expect($html)->toContain('Заголовок от анализатора')
        ->toContain('Метрика 1')
        ->toContain('Показаны первые 20 строк из 25');
});

test('generic-рендер сводки показывает честное сообщение вместо пустого блока', function (): void {
    $html = view('filament.resources.fixtures.run-result', [
        'getState' => fn (): array => ['message' => 'Прогон ещё идёт — разбор появится после его завершения.'],
    ])->render();

    expect($html)->toContain('Прогон ещё идёт')
        ->not->toContain('<dl');
});
