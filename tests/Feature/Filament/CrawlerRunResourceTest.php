<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Filament\Resources\CrawlerRun\Pages\ListCrawlerRuns;
use App\Models\Crawler\CrawlerRun;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());
});

it('read-only ресурс показывает прогоны краулера', function () {
    $crawler = crawlerProfile();
    $run = CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Livewire::test(ListCrawlerRuns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$run]);
});
