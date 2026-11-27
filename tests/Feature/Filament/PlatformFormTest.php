<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Filament\Resources\Procurement\Platform\Platforms\Pages\CreatePlatform;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

test('форма площадки сохраняет настройки обхода', function (): void {
    artisan('guard:sync-roles');
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    actingAs($operator);

    Livewire::test(CreatePlatform::class)
        ->fillForm([
            'code' => 'eis',
            'name' => 'Единая информационная система',
            'base_url' => 'https://zakupki.gov.ru',
            'is_active' => true,
            'throttle' => [
                'requestsPerMinuteBase' => 20,
                'stages' => ['listing' => ['retries' => 4, 'timeoutSeconds' => 25]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $settings = Platform::query()->sole()->throttle;

    expect($settings?->requestsPerMinuteBase)->toBe(20)
        ->and($settings?->forStage(CrawlStage::Listing)?->retries)->toBe(4);
});
