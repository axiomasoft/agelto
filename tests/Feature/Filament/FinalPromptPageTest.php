<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Filament\Pages\FinalPromptPage;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
 * Витрина финального промта (P5.5, D45/D46): показывает СОХРАНЁННЫЙ payload вызова по
 * `llm_calls.request_ref`/`response_ref`, различает «ссылки нет» и «блоб истёк по lifecycle»,
 * и авторизуется ПРИНУДИТЕЛЬНО трейтом `HasAzGuardPage` — прямой URL закрыт без права.
 */

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);
    Storage::fake('s3');
    config(['crawler.raw_disk' => 's3']);
});

function actAsFinalPromptViewer(): void
{
    $user = User::factory()->create();
    $user->assignRole(SuperAdminRole::class);
    actingAs($user);
}

function seedRunForFinalPrompt(): AnalysisRun
{
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    return AnalysisRun::factory()->create(['document_version_id' => $version->id]);
}

test('страница показывает сохранённый payload выбранного вызова', function (): void {
    actAsFinalPromptViewer();

    $run = seedRunForFinalPrompt();

    Storage::disk('s3')->put('ai/llm-calls/2026/07/30/request-1.json', '{"contents":"эталонный промт"}');

    $call = LlmCall::factory()->create([
        'analysis_run_id' => $run->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'request_ref' => 'ai/llm-calls/2026/07/30/request-1.json',
        'response_ref' => null,
    ]);

    Livewire::test(FinalPromptPage::class, ['run' => $run->id])
        ->set('call', $call->id)
        ->assertSee('эталонный промт');
});

test('вызовы обоих шагов — первичного анализа и извлечения — видны в списке', function (): void {
    actAsFinalPromptViewer();

    $run = seedRunForFinalPrompt();

    $screening = LlmCall::factory()->create([
        'analysis_run_id' => $run->id,
        'purpose' => CoreLlmPurpose::Screening->value,
    ]);
    $extraction = LlmCall::factory()->create([
        'analysis_run_id' => $run->id,
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
    ]);

    Livewire::test(FinalPromptPage::class, ['run' => $run->id])
        ->assertCanSeeTableRecords([$screening, $extraction]);
});

test('истёкший по lifecycle блоб объясняет отсутствие, а не падает ошибкой', function (): void {
    actAsFinalPromptViewer();

    $run = seedRunForFinalPrompt();

    $call = LlmCall::factory()->create([
        'analysis_run_id' => $run->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'request_ref' => 'ai/llm-calls/2026/07/30/request-давно-удалён.json',
        'response_ref' => null,
    ]);

    Livewire::test(FinalPromptPage::class, ['run' => $run->id])
        ->set('call', $call->id)
        ->assertSee('Payload удалён политикой хранения (срок — 90 дн.)');
});

test('без ссылки на payload показано честное «ссылки нет», а не пустой блок', function (): void {
    actAsFinalPromptViewer();

    $run = seedRunForFinalPrompt();

    $call = LlmCall::factory()->create([
        'analysis_run_id' => $run->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'request_ref' => null,
        'response_ref' => null,
    ]);

    Livewire::test(FinalPromptPage::class, ['run' => $run->id])
        ->set('call', $call->id)
        ->assertSee('Ссылки на payload нет.');
});

test('без права страница недоступна по прямому URL', function (): void {
    $guest = User::factory()->create();
    actingAs($guest);

    $run = seedRunForFinalPrompt();

    get(FinalPromptPage::getUrl(['run' => $run->id]))->assertForbidden();
});
