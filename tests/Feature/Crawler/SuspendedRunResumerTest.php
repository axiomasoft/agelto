<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\ControlMode;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\User;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Network\LegacyAcquisitionControl;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

function suspendedRun(Crawler $crawler, RunPurpose $purpose = RunPurpose::Profile): Run
{
    return Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'purpose' => $purpose,
        'cursor' => (new ListingCursor(blockFrom: 501))->toArray(),
        'stats' => [
            'source_suspended' => true,
            'stop_reason' => 'outbound_dependency',
            'execution_context' => ['mode' => CrawlerMode::Fresh->value, 'execution_lane' => $crawler->execution_lane->value, 'from' => null, 'to' => null],
        ],
    ]);
}

beforeEach(function (): void {
    LegacyBusinessNetwork::install();
    Queue::fake();
    $this->crawler = Crawler::factory()->create();
    $this->platformId = $this->crawler->registry->platform_id;
});

test('автоповтор отправляет job и сохраняет метку до фактического возобновления', function (): void {
    $run = suspendedRun($this->crawler);
    Artisan::call('crawler:recover-stale');
    Artisan::call('crawler:recover-stale');
    Queue::assertPushed(CrawlRunJob::class, 1);
    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $this->crawler->id && $job->mode === CrawlerMode::Fresh);
    expect($run->fresh()->stats->has('source_suspended'))->toBeTrue()
        ->and(DB::table('prc_crawler_runs')->where('id', $run->id)->value('source_resume_claimed_until'))->not->toBeNull()
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::RunResumed)->count())->toBe(0)
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
});

test('автоповтор ждёт retry_at и не трогает ручную паузу', function (): void {
    $run = suspendedRun($this->crawler);
    $run->update(['next_retry_at' => now()->addMinutes(5)]);
    Artisan::call('crawler:recover-stale');
    Queue::assertNothingPushed();
    $run->update(['next_retry_at' => now()->subMinute()]);
    $key = 'acquisition:platform:'.$this->crawler->registry->platform->code;
    $scope = DB::table('prc_control_scopes')->where('scope_key', $key)->sole();
    app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->setMode($key, ControlMode::Paused, null,
        'Ручная пауза источника', $scope->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    Artisan::call('crawler:recover-stale');
    Queue::assertNothingPushed();
    expect($run->fresh()->status)->toBe(RunStatus::Paused);
});

test('оператор возобновляет сразу, минуя ожидание: ожидание снято, прогон поставлен', function (): void {
    $run = suspendedRun($this->crawler);
    $run->update(['next_retry_at' => now()->addHour(), 'attempts' => 3, 'failed_permanently' => true,
        'stats' => [...$run->stats->toArray(), 'input_failure' => ['fingerprint' => hash('sha256', 'same-input'), 'limit' => 3]]]);
    $actor = User::factory()->create();
    $cursor = $run->refresh()->cursor->toArray();
    app(StartCrawlerRunAction::class)->resume($this->crawler, actorId: $actor->id, reason: 'Проверен сохранённый вход');
    expect($run->refresh()->failed_permanently)->toBeFalse()->and($run->attempts)->toBe(0)
        ->and($run->next_retry_at)->toBeNull()->and($run->cursor->toArray())->toBe($cursor)
        ->and($run->stats->get('input_recovery')['actor_id'])->toBe($actor->id);
    Queue::assertPushed(CrawlRunJob::class, 1);
});

test('delta/backlog сохраняют метку и продолжаются своим планировщиком', function (): void {
    $run = suspendedRun($this->crawler, RunPurpose::Delta);
    Artisan::call('crawler:recover-stale');
    Queue::assertNothingPushed();
    expect($run->fresh()->stats->has('source_suspended'))->toBeTrue()
        ->and($run->status)->toBe(RunStatus::Paused);
});

test('ожидание этапа карточек истекает без прогонов: снимается только retry_at', function (): void {
    $target = DB::table('prc_source_targets')->where('platform_id', $this->platformId)->where('capability', 'detail')->sole();
    DB::table('prc_target_health')->where('target_id', $target->id)->update(['state' => 'open', 'open_until' => now()->subMinute()]);
    $run = suspendedRun($this->crawler);
    $run->update(['stats' => [...$run->stats->toArray(), 'source_suspended' => false]]);
    Artisan::call('crawler:recover-stale');
    Queue::assertNothingPushed();
    expect(DB::table('prc_target_health')->where('target_id', $target->id)->sole()->state)->toBe('open')
        ->and($run->refresh()->status)->toBe(RunStatus::Paused);
});

test('absolute network RetryAt сохраняется вместе с pause и не расходует input budget', function (): void {
    $runs = app(CrawlerRunManager::class);
    $run = $runs->startOrResume($this->crawler, RunPurpose::Profile, null);
    $runs->recordExecutionContext($run, $this->crawler, CrawlerMode::Fresh, $this->crawler->execution_lane, null, null);
    expect($runs->acquireExecutionLease($run))->toBeTrue();
    $run->update(['attempts' => 2, 'next_retry_at' => now()->addHours(2),
        'stats' => [...$run->stats->toArray(), 'input_failure' => ['fingerprint' => hash('sha256', 'saved-input'), 'limit' => 50]]]);
    $cursor = $run->cursor->toArray();
    $due = $run->next_retry_at->toIso8601String();
    $failure = new OutboundUnavailable(
        new TransportDeferred(NetworkDeferralReason::RateLimit,
            new RetryAt(new DateTimeImmutable('+1 hour'))));
    app(LegacyAcquisitionControl::class)->onBlockException($this->crawler, $run, $failure, []);
    expect($run->refresh()->status)->toBe(RunStatus::Paused)->and($run->execution_owner_token)->toBeNull()
        ->and($run->attempts)->toBe(2)->and($run->cursor->toArray())->toBe($cursor)
        ->and($run->next_retry_at->toIso8601String())->toBe($due)
        ->and($run->stats->get('input_failure')['limit'])->toBe(50);
    Artisan::call('crawler:recover-stale');
    Artisan::call('crawler:recover-stale');
    Queue::assertNothingPushed();
    DB::table('prc_crawler_runs')->where('id', $run->id)->update(['next_retry_at' => now()->subMinute()]);
    Artisan::call('crawler:recover-stale');
    Artisan::call('crawler:recover-stale');
    Queue::assertPushed(CrawlRunJob::class, 1);
});
