<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Prompts\Pages\ListPrompts;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\ViewPrompt;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Prompt;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);

    $user = User::factory()->create();
    $user->assignRole(SuperAdminRole::class);
    actingAs($user);
});

test('список промтов показывает идентифицирующие колонки', function (): void {
    $prompt = Prompt::factory()->create([
        'document_type' => 'estimate',
        'key' => 'estimate-rows',
        'step' => 'extraction:rows',
        'version' => 3,
        'priority' => 10,
        'is_protected' => true,
    ]);

    Livewire::test(ListPrompts::class)
        ->assertCanSeeTableRecords([$prompt])
        ->assertSee('Тип документа')
        ->assertSee('Ключ')
        ->assertSee('Шаг')
        ->assertSee('Канон');
});

test('список промтов фильтруется по ключу шагу типу и признакам', function (): void {
    $expected = Prompt::factory()->create([
        'document_type' => 'estimate',
        'key' => 'estimate-rows',
        'step' => 'extraction:rows',
        'is_active' => true,
        'is_protected' => true,
    ]);
    $other = Prompt::factory()->create([
        'document_type' => 'protocol',
        'key' => 'protocol-screening',
        'step' => 'screening:classify',
        'is_active' => false,
        'is_protected' => false,
    ]);

    Livewire::test(ListPrompts::class)
        ->filterTable('key', $expected->key)
        ->assertCanSeeTableRecords([$expected])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('key', null)
        ->filterTable('step', $expected->step)
        ->assertCanSeeTableRecords([$expected])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('step', null)
        ->filterTable('document_type', $expected->document_type)
        ->assertCanSeeTableRecords([$expected])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('document_type', null)
        ->filterTable('is_active', 'true')
        ->assertCanSeeTableRecords([$expected])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('is_active', null)
        ->filterTable('is_protected', 'true')
        ->assertCanSeeTableRecords([$expected])
        ->assertCanNotSeeTableRecords([$other]);
});

test('карточка показывает полное тело и все версии одной пары', function (): void {
    $first = Prompt::factory()->create([
        'document_type' => 'estimate',
        'key' => 'estimate-rows',
        'step' => 'extraction:rows',
        'version' => 1,
        'content' => "Первая строка\nВторая строка",
        'is_protected' => true,
    ]);
    $second = Prompt::factory()->create([
        'document_type' => 'estimate',
        'key' => $first->key,
        'step' => 'extraction:rows',
        'version' => 2,
        'content' => 'Полное новое тело промта',
        'is_protected' => true,
    ]);

    Livewire::test(ViewPrompt::class, ['record' => $second->getKey()])
        ->assertSee('Полное новое тело промта')
        ->assertSee('Все версии этого промта')
        ->assertSee('Версии той же пары')
        ->assertSee('Версия')
        ->assertSee('Канон');
});
