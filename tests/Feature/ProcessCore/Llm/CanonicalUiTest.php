<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers\RunsRelationManager;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\User;
use App\Services\Llm\LlmPayloadReader;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Analyzer\TechSpecFakeAnalyzer;
use Tests\Support\ProcessCore\AiUiFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('acquisition-operations', 'analysis-execution');

function canonicalUiViewer(): void
{
    test()->artisan('guard:sync-roles')->assertSuccessful();
    $viewer = User::factory()->create();
    $viewer->assignRole(SuperAdminRole::class);
    test()->actingAs($viewer);
}

test('canonical history renders derived status money and unknown token evidence from the original provider ledger', function (): void {
    [$fixture,$run] = AiUiFixtures::accepted();
    canonicalUiViewer();
    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->assertCanSeeTableRecords([$run])
        ->assertTableColumnStateSet('execution_state', WorkState::Succeeded, record: $run)
        ->assertTableColumnStateSet('prompt_tokens', null, record: $run)
        ->assertTableColumnStateSet('cached_tokens', null, record: $run)
        ->assertTableColumnStateSet('completion_tokens', null, record: $run)
        ->assertTableColumnStateSet('total_tokens', null, record: $run)
        ->assertTableColumnStateSet('cost_micros', 2000, record: $run)
        ->assertTableColumnFormattedStateSet('cost_micros', '$0.0020', record: $run)
        ->assertTableColumnStateSet('reserved_micros', 0, record: $run)
        ->assertTableColumnStateSet('requested_reason', $run->requested_reason, record: $run)
        ->assertSee('нет данных');
    Http::assertNothingSent();
});

test('canonical history renders known token breakdown and active Running work state when extraction is queued', function (): void {
    [$fixture, $run] = AiUiFixtures::accepted(
        usage: ['unknown' => false, 'uncached_input' => 1200, 'cached_input' => 800, 'output' => 350, 'thinking' => 100],
        documentType: 'estimate',
        cardinality: 'single',
    );
    canonicalUiViewer();
    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->assertCanSeeTableRecords([$run])
        ->assertTableColumnStateSet('execution_state', WorkState::Ready, record: $run)
        ->assertTableColumnStateSet('prompt_tokens', 1200, record: $run)
        ->assertTableColumnStateSet('cached_tokens', 800, record: $run)
        ->assertTableColumnStateSet('completion_tokens', 350, record: $run)
        ->assertTableColumnStateSet('total_tokens', 2450, record: $run)
        ->assertTableColumnStateSet('cost_micros', 2000, record: $run)
        ->mountTableAction('result', record: $run)
        ->assertSchemaComponentStateSet('screening_branch', 'extract/analyzer_available')
        ->assertSchemaComponentExists('summary', checkComponentUsing: function (ViewEntry $entry): bool {
            expect($entry->getState()['message'] ?? null)
                ->toContain('Результат разбора ещё не опубликован');

            return true;
        });
    Http::assertNothingSent();
});

test('canonical result modal renders accepted screening and explains a missing extraction without an execution state writer', function (): void {
    [$fixture,$run] = AiUiFixtures::accepted();
    canonicalUiViewer();
    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->mountTableAction('result', record: $run)
        ->assertSchemaComponentStateSet('screening_status', AnalysisStatus::Succeeded)
        ->assertSchemaComponentStateSet('screening_document_type', 'tech_spec')
        ->assertSchemaComponentStateSet('screening_confidence', '0.95')
        ->assertSchemaComponentStateSet('screening_description', 'Локальная смета')
        ->assertSchemaComponentStateSet('screening_tags', 'смета, раздел-1')
        ->assertSchemaComponentStateSet('screening_branch', 'retain/known_type_no_analyzer')
        ->assertSchemaComponentExists('summary', checkComponentUsing: function (ViewEntry $entry): bool {
            expect($entry->getState()['message'] ?? null)
                ->toContain('Первичный анализ завершён: retain. Разбор не планировался.');

            return true;
        })
        ->assertSchemaComponentExists('gold_absent', checkComponentUsing: function (TextEntry $entry): bool {
            expect($entry->isVisible())->toBeTrue()
                ->and($entry->getState())->toContain('Эталона нет', 'estimate:gold:import');

            return true;
        })
        ->assertSchemaComponentHidden('gold_task')
        ->assertSchemaComponentHidden('gold_version')
        ->assertSchemaComponentHidden('gold_source')
        ->assertSchemaComponentHidden('gold_body')
        ->assertSchemaComponentDoesNotExist('cost_micros')
        ->assertSchemaComponentDoesNotExist('prompt_tokens');
});

test('canonical result modal delegates extraction summary to the registered analyzer and reports unregistered types without UI code changes', function (): void {
    [$fixture, $run] = AiUiFixtures::accepted();
    Analysis::factory()->create([
        'document_version_id' => $run->document_version_id,
        'analysis_run_id' => $run->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::TechSpec->value,
        'result' => ['sections' => 3],
    ]);
    $run->unsetRelation('analyses');
    canonicalUiViewer();

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->mountTableAction('result', record: $run)
        ->assertSchemaComponentExists('summary', checkComponentUsing: function (ViewEntry $entry): bool {
            expect($entry->getState()['message'] ?? null)
                ->toContain('Для типа «tech_spec» анализатор не зарегистрирован');

            return true;
        });

    config()->set('ai.analyzers', [AiDocumentType::TechSpec->value => TechSpecFakeAnalyzer::class]);
    app()->forgetInstance(DocumentAnalyzerRegistry::class);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->mountTableAction('result', record: $run)
        ->assertSchemaComponentExists('summary', checkComponentUsing: function (ViewEntry $entry): bool {
            $state = $entry->getState();
            expect($state['title'] ?? null)->toBe('Фиктивный второй тип')
                ->and($state['metrics'][0] ?? null)->toBe(['label' => 'Позиции', 'value' => '0', 'unit' => null]);

            return true;
        });
});

test('canonical result modal selects the newest task gold and explicitly labels its truncated read-only preview', function (): void {
    [$fixture,$run] = AiUiFixtures::accepted();
    foreach ([1, 2] as $version) {
        DatasetExample::factory()->create([
            'fixture_id' => $fixture->id, 'document_version_id' => $run->document_version_id,
            'task' => 'screening', 'version' => $version,
            'gold' => ['rows' => array_merge(['gold-version-'.$version], array_fill(0, 100, 'fixture'))],
        ]);
    }
    canonicalUiViewer();
    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->mountTableAction('result', record: $run)
        ->assertSchemaComponentHidden('gold_absent')
        ->assertSchemaComponentStateSet('gold_task', 'screening')
        ->assertSchemaComponentStateSet('gold_version', 2)
        ->assertSchemaComponentExists('gold_body', checkComponentUsing: function (TextEntry $entry): bool {
            expect($entry->getState())->toContain('gold-version-2', 'показаны первые 40 строк из')
                ->not->toContain('gold-version-1');

            return true;
        });
});

test('canonical payload page selects only exact run linked UUIDs and distinguishes an unavailable immutable object', function (): void {
    [, $run,,$call] = AiUiFixtures::accepted();
    canonicalUiViewer();
    $page = Livewire::test(FinalPromptPage::class, ['run' => $run->id])
        ->assertCanSeeTableRecords([$call])
        ->set('call', $call->physical_call_id)
        ->assertSee('finishReason')
        ->assertSee('Ссылки на payload нет');
    expect($page->instance()->selectedCall()->physical_call_id)->toBe($call->physical_call_id)
        ->and($page->instance()->requestPayload($call)->available)->toBeFalse();
    $page->set('call', LedgerFixtures::uuid()->value);
    expect($page->instance()->selectedCall())->toBeNull();
    Storage::disk($call->receipt_disk)->delete($call->receipt_key);
    expect(app(LlmPayloadReader::class)->receipt($call)->available)->toBeFalse();
    $page->set('call', $call->physical_call_id)->assertSee('Ответ недоступен');
    Http::assertNothingSent();
});

test('canonical AI pages preserve direct URL permissions and malformed UUID rejection', function (): void {
    [$fixture, $run] = AiUiFixtures::accepted();
    test()->artisan('guard:sync-roles')->assertSuccessful();
    test()->actingAs(User::factory()->create());
    expect(FixtureResource::canView($fixture))->toBeFalse();
    test()->get(FixtureResource::getUrl('view', ['record' => $fixture]))->assertForbidden();
    test()->get(FinalPromptPage::getUrl(['run' => $run->id]))->assertForbidden();
    canonicalUiViewer();
    Livewire::test(FinalPromptPage::class, ['run' => 'not-a-uuid'])->assertNotFound();
});
