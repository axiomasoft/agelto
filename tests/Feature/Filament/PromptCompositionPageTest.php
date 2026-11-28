<?php

declare(strict_types=1);

use App\Filament\Pages\PromptCompositionPage;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Prompt;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

function promptCompositionViewer(): void
{
    $user = User::factory()->create();
    $user->assignRole(SuperAdminRole::class);
    actingAs($user);
}

test('экран композиции берёт шаги из реестра и показывает цепочку правила и версии', function (): void {
    promptCompositionViewer();

    Prompt::factory()->create([
        'document_type' => 'any',
        'key' => 'global-rule',
        'step' => 'global',
        'version' => 1,
        'content' => 'Общее правило',
        'priority' => 1,
        'is_protected' => true,
    ]);
    Prompt::factory()->create([
        'document_type' => 'estimate',
        'key' => 'rows-rule',
        'step' => 'extraction:rows',
        'version' => 4,
        'content' => 'Правило строк',
        'priority' => 2,
        'is_protected' => true,
    ]);

    Livewire::test(PromptCompositionPage::class)
        ->set('documentType', 'estimate')
        ->set('step', 'extraction:rows')
        ->assertSee('global → extraction:global → extraction:rows')
        ->assertSee('global-rule')
        ->assertSee('rows-rule')
        ->assertSee('Общее правило')
        ->assertSee('Правило строк')
        ->assertSee('"rows-rule": 4');
});

test('экран композиции явно отличает правила от финального промта прогона', function (): void {
    promptCompositionViewer();

    Livewire::test(PromptCompositionPage::class)
        ->assertSee('без блока схем')
        ->assertSee('не воспроизводит текст, реально ушедший в модель')
        ->assertSee('Финальный промт прогона');
});

test('без права просмотра промтов прямой URL композиции закрыт', function (): void {
    $user = User::factory()->create();
    $user->assignRole(AnalystRole::class);
    actingAs($user);

    get(PromptCompositionPage::getUrl())->assertForbidden();
});

test('подмена step на недопустимое значение для типа документа откатывается к дефолтному шагу', function (): void {
    promptCompositionViewer();

    Livewire::test(PromptCompositionPage::class)
        ->set('documentType', 'any')
        ->set('step', 'extraction:rows')
        ->assertSuccessful()
        ->assertSee('global');
});
