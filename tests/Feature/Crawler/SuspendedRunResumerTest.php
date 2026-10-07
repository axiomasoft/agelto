<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Services\Procurement\SourceControl\SourceCircuitController;
use App\Services\Procurement\SourceControl\SuspendedRunResumer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

function suspendedRun(Crawler $crawler, RunPurpose $purpose = RunPurpose::Profile): Run
{
    return Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'purpose' => $purpose,
        'stats' => [
            'source_suspended' => true,
            'stop_reason' => 'source_circuit_open',
            'execution_context' => ['mode' => CrawlerMode::Fresh->value, 'execution_lane' => $crawler->execution_lane->value, 'from' => null, 'to' => null],
        ],
    ]);
}

beforeEach(function (): void {
    Queue::fake();
    $this->crawler = Crawler::factory()->create();
    $this->platformId = $this->crawler->registry->platform_id;
});

test('автоповтор: истёкшая пауза поднимает приостановленный прогон, метка снята', function (): void {
    SourceCircuit::factory()->create(['platform_id' => $this->platformId, 'retry_at' => now()->subSecond()]);
    $run = suspendedRun($this->crawler);

    Artisan::call('crawler:source-circuits');

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $this->crawler->id && $job->mode === CrawlerMode::Fresh);
    expect($run->fresh()->stats->has('source_suspended'))->toBeFalse()
        ->and(SourceCircuit::query()->sole()->retry_at)->toBeNull()
        ->and(SourceIncident::query()->where('type', SourceIncidentType::RunResumed)->sole()->run_id)->toBe($run->id);
});

test('автоповтор ждёт retry_at и не трогает ручную паузу', function (): void {
    $circuit = SourceCircuit::factory()->create(['platform_id' => $this->platformId, 'retry_at' => now()->addMinutes(5)]);
    suspendedRun($this->crawler);

    Artisan::call('crawler:source-circuits');
    Queue::assertNothingPushed();

    $circuit->update(['retry_at' => now()->subMinute(), 'manually_paused' => true]);
    Artisan::call('crawler:source-circuits');
    Queue::assertNothingPushed();
});

test('оператор возобновляет сразу, минуя ожидание: ожидание снято, прогон поставлен', function (): void {
    SourceCircuit::factory()->create(['platform_id' => $this->platformId, 'retry_at' => now()->addHour()]);
    suspendedRun($this->crawler);

    app(SourceCircuitController::class)->resume(SourceCircuit::query()->sole(), 'operator@agelto.ru');
    $dispatched = app(SuspendedRunResumer::class)->resumePlatform(platformId: $this->platformId, actor: 'operator@agelto.ru');

    expect($dispatched)->toBe(1)
        ->and(SourceCircuit::query()->sole()->retry_at)->toBeNull();
    Queue::assertPushed(CrawlRunJob::class);
});

test('delta/backlog прогоны не диспатчатся резюмером, но метка снимается', function (): void {
    SourceCircuit::factory()->create(['platform_id' => $this->platformId, 'retry_at' => now()->subMinute()]);
    $run = suspendedRun($this->crawler, RunPurpose::Delta);

    Artisan::call('crawler:source-circuits');

    Queue::assertNothingPushed();
    expect($run->fresh()->stats->has('source_suspended'))->toBeFalse();
});

test('ожидание этапа карточек истекает без прогонов: снимается только retry_at', function (): void {
    SourceCircuit::factory()->create(['platform_id' => $this->platformId, 'stage' => SourceStage::Detail, 'retry_at' => now()->subMinute()]);
    suspendedRun($this->crawler);

    Artisan::call('crawler:source-circuits');

    Queue::assertNothingPushed();
    expect(SourceCircuit::query()->sole()->retry_at)->toBeNull();
});
