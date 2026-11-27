<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ListPromptSuggestions;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ViewPromptSuggestion;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Prompt;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

// `artisan()` без потребления возврата откладывает выполнение до __destruct() PendingCommand —
// форсируем синхронный прогон явным assert (тот же приём, что FixtureRunScreenTest).
beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

/** Владелец: решение о тексте системной инструкции — его право (Update у суперадмина). */
function promptSuggestionOwner(): User
{
    $owner = User::factory()->create();
    $owner->assignRole(SuperAdminRole::class);

    return $owner;
}

/**
 * Снимок «путь → sha1» обоих корней канона промтов — доказательство, что экран файлов не
 * пишет, не зависит от git-состояния рабочей копии.
 *
 * @return array<string, string>
 */
function promptCanonSnapshot(): array
{
    $snapshot = [];

    foreach (Finder::create()->files()->in(resource_path())->path('ai/prompts')->name('*.md') as $file) {
        $snapshot[(string) $file->getRealPath()] = sha1_file((string) $file->getRealPath());
    }

    return $snapshot;
}

test('список по умолчанию показывает только нерешённые предложения', function (): void {
    actingAs(promptSuggestionOwner());

    $pending = PromptSuggestion::factory()->create();
    $decided = PromptSuggestion::factory()->approved()->create();

    Livewire::test(ListPromptSuggestions::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$decided]);
});

test('список фильтруется по типу документа и стадии предмета', function (): void {
    actingAs(promptSuggestionOwner());

    $estimateExtraction = PromptSuggestion::factory()->create([
        'document_type' => AiDocumentType::Estimate->value,
        'analysis_step' => AnalysisStep::Extraction,
    ]);
    $protocolScreening = PromptSuggestion::factory()->create([
        'document_type' => AiDocumentType::Protocol->value,
        'analysis_step' => AnalysisStep::Screening,
    ]);

    Livewire::test(ListPromptSuggestions::class)
        ->filterTable('document_type', AiDocumentType::Estimate->value)
        ->assertCanSeeTableRecords([$estimateExtraction])
        ->assertCanNotSeeTableRecords([$protocolScreening])
        ->filterTable('document_type', null)
        ->filterTable('analysis_step', AnalysisStep::Screening->value)
        ->assertCanSeeTableRecords([$protocolScreening])
        ->assertCanNotSeeTableRecords([$estimateExtraction]);
});

test('карточка показывает текущее тело промта рядом с предложенным', function (): void {
    actingAs(promptSuggestionOwner());

    $suggestion = PromptSuggestion::factory()->create([
        'proposed_content' => 'Новое тело инструкции',
    ]);

    Prompt::factory()->create([
        'document_type' => $suggestion->document_type,
        'key' => $suggestion->prompt_key,
        'version' => $suggestion->base_version,
        'content' => 'Текущее тело инструкции',
    ]);

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->assertSee('Текущее тело инструкции')
        ->assertSee('Новое тело инструкции');
});

test('одобрение ставит статус и пишет аудит решения', function (): void {
    $owner = promptSuggestionOwner();
    actingAs($owner);

    $suggestion = PromptSuggestion::factory()->create();

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->callAction('approve', ['decision_note' => 'Согласен с критиком']);

    $suggestion->refresh();

    expect($suggestion->status)->toBe(PromptSuggestionStatus::Approved)
        ->and($suggestion->decided_by)->toBe($owner->id)
        ->and($suggestion->decided_at)->not->toBeNull()
        ->and($suggestion->decision_note)->toBe('Согласен с критиком');
});

test('одобрение не трогает ни одного файла канона промтов', function (): void {
    actingAs(promptSuggestionOwner());

    $suggestion = PromptSuggestion::factory()->create();
    $before = promptCanonSnapshot();

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->callAction('approve', ['decision_note' => null]);

    // Первое ожидание — что действие ВООБЩЕ отработало: «файлы не тронуты» при отсутствующем
    // апруве зеленело бы, ничего не проверяя (Code Guidance ).
    expect($suggestion->refresh()->status)->toBe(PromptSuggestionStatus::Approved)
        ->and(promptCanonSnapshot())->toBe($before)
        ->and($before)->not->toBeEmpty();
});

test('отклонение без причины не проходит', function (): void {
    actingAs(promptSuggestionOwner());

    $suggestion = PromptSuggestion::factory()->create();

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->callAction('reject', ['decision_note' => null])
        ->assertHasActionErrors(['decision_note' => ['required']]);

    expect($suggestion->refresh()->status)->toBe(PromptSuggestionStatus::Pending);
});

test('отклонение с причиной ставит статус и причину', function (): void {
    actingAs(promptSuggestionOwner());

    $suggestion = PromptSuggestion::factory()->create();

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->callAction('reject', ['decision_note' => 'Патч ломает формат ответа']);

    $suggestion->refresh();

    expect($suggestion->status)->toBe(PromptSuggestionStatus::Rejected)
        ->and($suggestion->decision_note)->toBe('Патч ломает формат ответа');
});

test('повторное решение по уже решённому предложению отклоняется', function (): void {
    actingAs(promptSuggestionOwner());

    $suggestion = PromptSuggestion::factory()->approved()->create();

    $test = Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');

    // `callAction()` сам гейтит на видимость — прямой вызов эмулирует устаревшую карточку
    // (клик до перерендера), для которой Implementation Rule 4 требует guard И внутри действия.
    $test->instance()->getAction(['approve'])->call(['decision_note' => 'ещё раз']);

    $test->assertNotified('Предложение уже решено (текущий статус: approved) — повторное решение отклонено.');

    expect($suggestion->refresh()->decision_note)->toBeNull();
});

test('карточка одобренного показывает команду применения с его id', function (): void {
    actingAs(promptSuggestionOwner());

    $suggestion = PromptSuggestion::factory()->approved()->create();

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->assertSee('Одобрено, но не применено')
        ->assertSee("ai:prompts:apply {$suggestion->id}");
});

test('оператор действий не видит, а прямой вызов получает отказ', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    $suggestion = PromptSuggestion::factory()->create();

    $test = Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');

    $test->instance()->getAction(['approve'])->call(['decision_note' => null]);

    $test->assertNotified('Недостаточно прав для решения по предложению');

    expect($suggestion->refresh()->status)->toBe(PromptSuggestionStatus::Pending);
});

test('аналитик видит очередь, но решать не может', function (): void {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst);

    $suggestion = PromptSuggestion::factory()->create();

    expect(PromptSuggestionResource::canViewAny())->toBeTrue();

    Livewire::test(ViewPromptSuggestion::class, ['record' => $suggestion->getKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});

test('без права на очередь — 403 по прямому URL', function (): void {
    actingAs(User::factory()->create());

    expect(PromptSuggestionResource::canViewAny())->toBeFalse();

    get(PromptSuggestionResource::getUrl('index'))->assertForbidden();
});
