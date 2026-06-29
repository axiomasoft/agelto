<?php

declare(strict_types=1);

use App\Filament\Resources\CrawlerRun\Pages\ListCrawlerRuns;
use App\Models\Crawler\CrawlerRun;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());
});

test('read-only ресурс показывает прогоны краулера', function () {
    $run = CrawlerRun::factory()->create();

    Livewire::test(ListCrawlerRuns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$run]);
});
