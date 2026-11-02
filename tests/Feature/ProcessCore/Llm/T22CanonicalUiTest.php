<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Process\WorkState;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers\RunsRelationManager;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\User;
use App\Services\Llm\LlmPayloadReader;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ProcessCore\AiUiFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t17', 't22', 'p313');

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
        ->assertTableColumnStateSet('total_tokens', null, record: $run)
        ->assertTableColumnStateSet('cost_micros', 2000, record: $run)
        ->assertTableColumnFormattedStateSet('cost_micros', '$0.0020', record: $run)
        ->assertTableColumnStateSet('reserved_micros', 0, record: $run)
        ->assertTableColumnStateSet('requested_reason', $run->requested_reason, record: $run);
    Http::assertNothingSent();
});

test('canonical result modal renders accepted screening and explains a missing extraction without an execution state writer', function (): void {
    [$fixture,$run] = AiUiFixtures::accepted();
    canonicalUiViewer();
    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->mountTableAction('result', record: $run)
        ->assertSchemaComponentStateSet('screening_status', AnalysisStatus::Succeeded)
        ->assertSchemaComponentStateSet('screening_document_type', 'tech_spec')
        ->assertSchemaComponentStateSet('screening_description', 'Локальная смета')
        ->assertSchemaComponentStateSet('screening_branch', 'retain/known_type_no_analyzer')
        ->assertSchemaComponentExists('summary')
        ->assertSchemaComponentDoesNotExist('cost_micros')
        ->assertSchemaComponentDoesNotExist('prompt_tokens');
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
    $page = Livewire::test(FinalPromptPage::class, ['run' => $run->id])->set('call', $call->physical_call_id)
        ->assertSee('finishReason');
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
    [, $run] = AiUiFixtures::accepted();
    test()->artisan('guard:sync-roles')->assertSuccessful();
    test()->actingAs(User::factory()->create());
    test()->get(FinalPromptPage::getUrl(['run' => $run->id]))->assertForbidden();
    canonicalUiViewer();
    Livewire::test(FinalPromptPage::class, ['run' => 'not-a-uuid'])->assertNotFound();
});
