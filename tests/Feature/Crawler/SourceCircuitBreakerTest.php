<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\SourceEvent;
use App\Dto\Procurement\Crawler\SourceFailure;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\TransportFailure;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\StageCode;
use App\Events\Procurement\SourceIncidentRecorded;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\User;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Network\AcquisitionEligibility;
use App\Services\Procurement\Network\HealthPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use App\Services\Procurement\SourceControl\SourceEventReporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProcessCore\Http\AdapterFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\Proxy\HealthFixtures;

// Historical scenario names remain exact. Retired per-SourceCircuit policy knobs are mapped
// explicitly to canonical authority/config contracts in the candidate's scenario-map.json.
beforeEach(function (): void {
    PermitFixtures::clean();
    $this->closers = [];
});
afterEach(function (): void {
    foreach ($this->closers as $close) {
        $close();
    } PermitFixtures::clean();
});

function sourceNetworkAuthority(): HealthFixtures
{
    $wire = new WireFixture;
    test()->closers = [...test()->closers, fn () => $wire->stop()];

    return new HealthFixtures($wire);
}

function sourceAdapterAuthority(): AdapterFixtures
{
    $f = new AdapterFixtures;
    test()->closers = [...test()->closers, fn () => $f->close()];

    return $f;
}

function canonicalSourceEvent(int $platform, SourceFailureKind $kind, SourceStage $stage = SourceStage::Listing): void
{
    $event = SourceEvent::forFailure(CrawlerEventCode::forFailure($stage, $kind),
        new SourceFailure($platform, $stage, $kind, 'тест'), 'тест');
    $reporter = app(SourceEventReporter::class);
    if ($kind === SourceFailureKind::NotFound) {
        $reporter->log($event);
    } else {
        $reporter->report($event);
    }
}

function canonicalInputFailures(int $count): Run
{
    $crawler = Crawler::factory()->create();
    $runs = app(CrawlerRunManager::class);
    for ($index = 0; $index < $count; $index++) {
        $run = $runs->startOrResume($crawler, RunPurpose::Profile, null);
        expect($runs->acquireExecutionLease($run))->toBeTrue();
        $runs->recordInputFailure($run, hash('sha256', 'same-input'), 50);
        if ($index + 1 < $count) {
            DB::table('prc_crawler_runs')->where('id', $run->id)->update(['next_retry_at' => now()->subMinute()]);
        }
    }

    return $run;
}

test('отказ ниже порога: этап работает, событие отказа записано, ожидания нет', function (): void {
    $f = sourceNetworkAuthority();
    $proxy = $f->proxy();
    $platform = DB::table('prc_source_targets')->where('id', $f->permits->target->value)->sole()->platform_id;
    canonicalSourceEvent($platform, SourceFailureKind::SourceBlocked);
    $f->outcome(HealthFixtures::failure(TransportFailure::ProxyConnect), $proxy);
    expect(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole()->consecutive_failures)->toBe(1)
        ->and(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole()->state)->toBe('closed')
        ->and(SourceIncident::query()->pluck('type')->all())->toBe([CrawlerEventCode::CsvSourceBlocked]);
});

test('порог: ожидание на интервал, событие об ожидании, доменное событие', function (): void {
    $f = sourceNetworkAuthority();
    $proxy = $f->proxy();
    Event::fake([SourceIncidentRecorded::class]);
    $platform = DB::table('prc_source_targets')->where('id', $f->permits->target->value)->sole()->platform_id;
    canonicalSourceEvent($platform, SourceFailureKind::SourceUnavailable);
    foreach (range(1, app(HealthPolicy::class)->globalFailures) as $ignored) {
        $f->outcome(HealthFixtures::failure(TransportFailure::ProxyConnect), $proxy);
    }
    $row = DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole();
    expect($row->state)->toBe('open')->and($row->open_until)->not->toBeNull()
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
    Event::assertDispatched(SourceIncidentRecorded::class);
});

test('изменилась структура и порог: этап остановлен до оператора, таймера нет', function (): void {
    $f = sourceAdapterAuthority();
    foreach (['one', 'two', 'three'] as $input) {
        $f->validate($input, AdapterVerdict::ContractFailure);
    }
    $row = DB::table('prc_adapter_health')->sole();
    expect($row->state)->toBe('held')->and($row->open_until)->toBeNull();
    $this->travel(2)->hours();
    expect(fn () => $f->validate('blocked'))->toThrow(OutboundUnavailable::class)
        ->and($f->wire->receipts())->toHaveCount(3)
        ->and(DB::table('prc_process_incidents')->where('kind', 'adapter_contract_held')->count())->toBe(1);
});

test('ошибки нашего кода и пустое тело не считаются брейкером, но пишутся в журнал и лог', function (): void {
    $f = sourceNetworkAuthority();
    $platform = DB::table('prc_source_targets')->where('id', $f->permits->target->value)->sole()->platform_id;
    Log::spy();
    foreach (range(1, 2) as $ignored) {
        canonicalSourceEvent($platform, SourceFailureKind::CodeError, SourceStage::Detail);
        canonicalSourceEvent($platform, SourceFailureKind::EmptyBody);
    }
    expect(SourceIncident::query()->where('type', CrawlerEventCode::CardCodeError)->count())->toBe(2)
        ->and(DB::table('prc_route_health')->sole()->consecutive_failures)->toBe(0);
    Log::shouldHaveReceived('log')->with('error', Mockery::pattern('/^CRAWLER_CARD_CODE_ERROR: /'), Mockery::any())->twice();
    Log::shouldHaveReceived('log')->with('critical', Mockery::pattern('/^CRAWLER_CSV_EMPTY: /'), Mockery::any())->twice();
});

test('после ожидания этап просто работает снова — без пробных запросов', function (): void {
    $f = sourceAdapterAuthority();
    foreach (['one', 'two', 'three'] as $input) {
        $f->validate($input, AdapterVerdict::ContractFailure);
    }
    $row = DB::table('prc_adapter_health')->sole();
    $actor = User::factory()->create();
    app(ProcessTransaction::class)->run(fn () => app(AdapterHealthRepository::class)->reset($f->contract, $row->state_version, $actor->id, 'Исправлен контракт'));
    expect(DB::table('prc_adapter_health')->sole()->state)->toBe('validating');
    $f->validate('canary');
    expect(DB::table('prc_adapter_health')->sole()->state)->toBe('healthy')
        ->and(DB::table('prc_request_attempts')->count())->toBe(4);
});

test('успех сбрасывает сетевой счётчик; ошибки строк имеют отдельный лимит', function (): void {
    $f = sourceNetworkAuthority();
    $proxy = $f->proxy();
    $f->outcome(HealthFixtures::failure(TransportFailure::ProxyConnect), $proxy);
    $f->outcome(HealthFixtures::success(), $proxy);
    $run = canonicalInputFailures(1);
    expect(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole()->consecutive_failures)->toBe(0)
        ->and($run->attempts)->toBe(1)->and($run->failed_permanently)->toBeFalse();
});

test('постоянные ошибки отдельных строк ограничены числом повторов', function (): void {
    $run = canonicalInputFailures(50);
    expect($run->attempts)->toBe(50)->and($run->failed_permanently)->toBeTrue()->and($run->status)->toBe(RunStatus::Paused)
        ->and($run->execution_owner_token)->toBeNull()->and($run->cursor->get('blockFrom'))->toBe(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('этапы независимы: ожидание карточек не останавливает листинг', function (): void {
    $f = sourceNetworkAuthority();
    $n = $f->permits;
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($n->code, [
        new SourceTargetDefinition('card', 'detail', $n->url->origin, [$n->profile], new OriginBudgetPolicy(600, 1, 2)),
        new SourceTargetDefinition('listing', 'listing', $n->url->origin, [$n->profile], new OriginBudgetPolicy(600, 1, 2)),
    ]));
    DB::table('prc_target_health')->where('target_id', $n->target->value)->update(['state' => 'open', 'open_until' => now()->addMinutes(5)]);
    $platform = DB::table('prc_source_targets')->where('id', $n->target->value)->sole()->platform_id;
    expect(app(AcquisitionEligibility::class)->excludedPlatforms(StageCode::Detail))->toContain($platform)
        ->and(app(AcquisitionEligibility::class)->excludedPlatforms(StageCode::Listing))->not->toContain($platform);
});

test('выключенный брейкер не уходит в ожидание; отказ во время ожидания не считается', function (): void {
    $f = sourceNetworkAuthority();
    $proxy = $f->proxy();
    DB::table('prc_network_routes')->where('id', $proxy->id->value)->update(['enabled' => false]);
    $selected = app(ProxyResolver::class)->outboundRoutes(new RoutePolicy([$proxy], $f->profile))
        ->select($f->permits->plan(), new RetryRoutePreference([], null, false, 2));
    expect($selected)->toBeInstanceOf(TransportDeferred::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole()->consecutive_failures)->toBe(0);
});

test('пороги из админки применяются к следующему отказу', function (): void {
    config(['outbound.health.global_consecutive' => 1, 'outbound.health.global_base_seconds' => 1800, 'outbound.health.global_cap_seconds' => 1800]);
    $f = sourceNetworkAuthority();
    $proxy = $f->proxy();
    $f->outcome(HealthFixtures::failure(TransportFailure::ProxyConnect), $proxy);
    $row = DB::selectOne('SELECT state,extract(epoch FROM open_until-clock_timestamp()) AS seconds FROM prc_route_health WHERE route_id=?', [$proxy->id->value]);
    expect($row->state)->toBe('open')->and((float) $row->seconds)->toBeGreaterThan(1790)
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
});

test('оператор: пауза и «возобновить сейчас» снимает паузу, остановку и ожидание', function (): void {
    $f = sourceNetworkAuthority();
    $actor = User::factory()->create();
    $repo = app(ControlRepository::class);
    $tx = app(ProcessTransaction::class);
    $key = 'acquisition:platform:'.$f->permits->code;
    $scope = DB::table('prc_control_scopes')->where('scope_key', $key)->sole();
    $tx->run(fn () => $repo->setMode($key, ControlMode::Paused, $actor->id, 'Разбираемся с прокси', $scope->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    $platform = DB::table('prc_source_targets')->where('id', $f->permits->target->value)->sole()->platform_id;
    expect(app(AcquisitionEligibility::class)->excludedPlatforms(StageCode::Detail))->toContain($platform);
    $scope = DB::table('prc_control_scopes')->where('scope_key', $key)->sole();
    $tx->run(fn () => $repo->setMode($key, ControlMode::Enabled, $actor->id, 'Проверена конфигурация', $scope->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    expect(app(AcquisitionEligibility::class)->excludedPlatforms(StageCode::Detail))->not->toContain($platform)
        ->and(DB::table('prc_process_transitions')->where('actor_id', $actor->id)->count())->toBe(2);
});

test('счётчики раздельны: сетевой шум не сбрасывает и не прячет счёт структуры', function (): void {
    $f = sourceAdapterAuthority();
    $f->validate('one', AdapterVerdict::ContractFailure);
    expect(fn () => $f->networkFailure('noise'))->toThrow(OutboundUnavailable::class);
    $f->validate('two', AdapterVerdict::ContractFailure);
    $f->validate('three', AdapterVerdict::ContractFailure);
    expect(DB::table('prc_adapter_health')->sole()->state)->toBe('held')
        ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(3)
        ->and(DB::table('prc_request_attempts')->where('status', 503)->count())->toBe(1);
});

test('404 по умолчанию — только лог: ни журнала, ни счёта, этап работает', function (): void {
    $f = sourceNetworkAuthority();
    Log::spy();
    $platform = DB::table('prc_source_targets')->where('id', $f->permits->target->value)->sole()->platform_id;
    foreach (range(1, 5) as $ignored) {
        canonicalSourceEvent($platform, SourceFailureKind::NotFound);
    }
    $f->outcome(new HttpAttemptResult(new ResponseEnvelope(404, [], new DecodedTextBody('none', 'UTF-8'), 4, 4), null, 4, 4));
    expect(SourceIncident::query()->count())->toBe(0)->and(DB::table('prc_route_health')->sole()->consecutive_failures)->toBe(0);
    Log::shouldHaveReceived('log')->with('warning', Mockery::pattern('/^CRAWLER_CSV_NOT_FOUND: /'), Mockery::any())->times(5);
});

test('одна строка конфига делает 404 отказом площадки', function (): void {
    // Legacy reaction overrides are retired; a response code cannot create a second health owner.
    config(['crawler.source_control.stages.listing.reactions.not_found' => 'count_toward_wait']);
    $f = sourceNetworkAuthority();
    $response = new HttpAttemptResult(new ResponseEnvelope(404, [], new DecodedTextBody('none', 'UTF-8'), 4, 4), null, 4, 4);
    $f->outcome($response);
    $f->outcome($response);
    expect(DB::table('prc_route_health')->sole()->state)->toBe('closed')
        ->and(DB::table('prc_target_health')->sole()->state)->toBe('closed')
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
});

test('пороги: пустое поле в админке — из конфига, заполненное — перекрывает', function (): void {
    $defaults = app(HealthPolicy::class);
    expect($defaults->globalFailures)->toBe(config('outbound.health.global_consecutive'));
    config(['outbound.health.global_consecutive' => 7]);
    expect(app(HealthPolicy::class)->globalFailures)->toBe(7);
    config(['outbound.health.global_consecutive' => null]);
    expect(fn () => app(HealthPolicy::class))->toThrow(InvalidArgumentException::class, 'global_consecutive');
});
