<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// `artisan()` без потребления возврата откладывает выполнение до __destruct() PendingCommand
// (недетерминированный момент между тестами файла) — форсируем синхронный прогон явным assert.
beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

test('оператор видит действие «Прогнать» на карточке эталона', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    $fixture = Fixture::factory()->est()->create();

    Livewire::test(ViewFixture::class, ['record' => $fixture->getKey()])
        ->assertActionVisible('run');
});

test('без analysis.create действие «Прогнать» не видно, а прямой вызов получает отказ', function (): void {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst);

    $fixture = Fixture::factory()->est()->create();

    $test = Livewire::test(ViewFixture::class, ['record' => $fixture->getKey()])
        ->assertActionHidden('run');

    // `callAction()` сам гейтит на видимость (assertActionVisible внутри) — прямой вызов
    // закрытия эксплуатирует Action::call() в обход Livewire-пайплайна, эмулируя устаревшую
    // карточку (клик до перерендера) — тот самый случай, для которого Implementation Rule 7
    // требует проверку И внутри действия, не только в visible().
    $test->instance()->getAction(['run'])->call();

    $test->assertNotified('Недостаточно прав для запуска прогона');
});
