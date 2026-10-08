<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\SourceControlPolicy;
use App\Dto\Procurement\Crawler\SourceFailure;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceBreakerOutcome;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceFailureReaction;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Crawler\SourceStageStatus;
use App\Events\Procurement\SourceIncidentRecorded;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;
use App\Services\Procurement\SourceControl\SourceCircuitBreaker;
use App\Services\Procurement\SourceControl\SourceFailureReporter;
use App\Services\Procurement\SourceControl\SourceStageOperations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->breaker = app(SourceCircuitBreaker::class);
    $this->circuits = app(SourceCircuitRepository::class);
    $this->reporter = app(SourceFailureReporter::class);
    $this->platformId = Registry::factory()->eis()->create()->platform_id;

    foreach (SourceStage::cases() as $stage) {
        SourceCircuit::factory()->create(['platform_id' => $this->platformId, 'stage' => $stage, 'failure_threshold' => 2, 'layout_threshold' => 2, 'retry_interval_minutes' => 10]);
    }
    $this->fail = fn (SourceFailureKind $kind, SourceStage $stage = SourceStage::Listing): bool => $this->reporter->report(
        new SourceFailure(platformId: $this->platformId, stage: $stage, kind: $kind, detail: 'тест'),
    );
});

test('отказ ниже порога: этап работает, событие отказа записано, ожидания нет', function (): void {
    expect(($this->fail)(SourceFailureKind::SourceBlocked))->toBeTrue()
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->consecutive_failures)->toBe(1)
        ->and(SourceIncident::query()->pluck('type')->all())->toBe([CrawlerEventCode::CsvSourceBlocked]);
});

test('порог: ожидание на интервал, событие об ожидании, доменное событие', function (): void {
    Event::fake([SourceIncidentRecorded::class]);

    ($this->fail)(SourceFailureKind::SourceUnavailable);
    $admits = ($this->fail)(SourceFailureKind::SourceUnavailable);
    $circuit = $this->circuits->forStage($this->platformId, SourceStage::Listing);

    expect($admits)->toBeFalse()
        ->and($circuit->retry_at?->diffInMinutes(now(), absolute: true))->toEqualWithDelta(10, 0.1)
        ->and($circuit->manually_paused)->toBeFalse()
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::StageWaiting)->count())->toBe(1);

    Event::assertDispatched(SourceIncidentRecorded::class);
});

test('изменилась структура и порог: этап остановлен до оператора, таймера нет', function (): void {
    ($this->fail)(SourceFailureKind::LayoutDrift, SourceStage::Detail);
    ($this->fail)(SourceFailureKind::LayoutDrift, SourceStage::Detail);
    $circuit = $this->circuits->forStage($this->platformId, SourceStage::Detail);

    expect($circuit->held_at)->not->toBeNull()
        ->and($circuit->manually_paused)->toBeFalse()
        ->and($circuit->retry_at)->toBeNull()
        ->and($circuit->status())->toBe(SourceStageStatus::Held)
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::StageHeld)->count())->toBe(1);

    $this->travel(2)->hours();
    expect($this->breaker->admits($this->platformId, SourceStage::Detail))->toBeFalse();
});

test('ошибки нашего кода и пустое тело не считаются брейкером, но пишутся в журнал и лог', function (): void {
    Log::spy();

    ($this->fail)(SourceFailureKind::CodeError, SourceStage::Detail);
    ($this->fail)(SourceFailureKind::CodeError, SourceStage::Detail);
    ($this->fail)(SourceFailureKind::EmptyBody);
    ($this->fail)(SourceFailureKind::EmptyBody);

    expect($this->breaker->admits($this->platformId, SourceStage::Detail))->toBeTrue()
        ->and($this->breaker->admits($this->platformId, SourceStage::Listing))->toBeTrue()
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::CardCodeError)->count())->toBe(2);

    Log::shouldHaveReceived('log')->with('error', Mockery::pattern('/^CRAWLER_CARD_CODE_ERROR: /'), Mockery::on(
        fn (array $tags): bool => $tags['code'] === 'CRAWLER_CARD_CODE_ERROR' && $tags['stage'] === 'detail' && $tags['platform_id'] === $this->platformId,
    ))->twice();
    Log::shouldHaveReceived('log')->with('critical', Mockery::pattern('/^CRAWLER_CSV_EMPTY: /'), Mockery::any())->twice();
});

test('после ожидания этап просто работает снова — без пробных запросов', function (): void {
    ($this->fail)(SourceFailureKind::SourceUnavailable);
    ($this->fail)(SourceFailureKind::SourceUnavailable);

    $this->travel(11)->minutes();

    expect($this->breaker->admits($this->platformId, SourceStage::Listing))->toBeTrue()
        ->and(($this->fail)(SourceFailureKind::SourceUnavailable))->toBeTrue();
});

test('успех сбрасывает сетевой счётчик; ошибки строк имеют отдельный лимит', function (): void {
    ($this->fail)(SourceFailureKind::SourceUnavailable);
    $this->reporter->reportSuccess($this->platformId, SourceStage::Listing);
    ($this->fail)(SourceFailureKind::RowErrors);

    expect($this->circuits->forStage($this->platformId, SourceStage::Listing)->consecutive_failures)->toBe(0)
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->consecutive_skips)->toBe(1);
});

test('постоянные ошибки отдельных строк ограничены числом повторов', function (): void {
    $this->circuits->forStage($this->platformId, SourceStage::Listing)->update(['consecutive_skips' => 49]);

    expect(($this->fail)(SourceFailureKind::RowErrors))->toBeFalse()
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->status())->toBe(SourceStageStatus::Held);
});

test('этапы независимы: ожидание карточек не останавливает листинг', function (): void {
    ($this->fail)(SourceFailureKind::SourceUnavailable, SourceStage::Detail);
    ($this->fail)(SourceFailureKind::SourceUnavailable, SourceStage::Detail);

    expect($this->breaker->admits($this->platformId, SourceStage::Detail))->toBeFalse()
        ->and($this->breaker->admits($this->platformId, SourceStage::Listing))->toBeTrue();
});

test('выключенный брейкер не уходит в ожидание; отказ во время ожидания не считается', function (): void {
    $this->circuits->forStage($this->platformId, SourceStage::Listing)->update(['enabled' => false]);
    ($this->fail)(SourceFailureKind::SourceUnavailable);
    expect(($this->fail)(SourceFailureKind::SourceUnavailable))->toBeTrue();

    SourceCircuit::query()->where('stage', SourceStage::Listing)->update(['enabled' => true, 'consecutive_failures' => 0, 'retry_at' => now()->addMinutes(5)]);
    expect($this->breaker->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable, SourceFailureReaction::CountTowardWait))->toBe(SourceBreakerOutcome::NotAdmitting)
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->consecutive_failures)->toBe(0);
});

test('пороги из админки применяются к следующему отказу', function (): void {
    $this->circuits->forStage($this->platformId, SourceStage::Listing)->update(['failure_threshold' => 1, 'retry_interval_minutes' => 30]);

    expect(($this->fail)(SourceFailureKind::InvalidContent))->toBeFalse()
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->retry_at?->diffInMinutes(now(), absolute: true))->toEqualWithDelta(30, 0.1);
});

test('оператор: пауза и «возобновить сейчас» снимает паузу, остановку и ожидание', function (): void {
    $operations = app(SourceStageOperations::class);
    $circuit = $this->circuits->forStage($this->platformId, SourceStage::Listing);
    $operations->pause($circuit, 'admin@agelto.ru', 'Разбираемся с прокси.');

    expect($this->breaker->admits($this->platformId, SourceStage::Listing))->toBeFalse();

    $operations->resume($circuit->refresh(), 'admin@agelto.ru');
    $circuit->refresh();

    expect($circuit->manually_paused)->toBeFalse()
        ->and($circuit->retry_at)->toBeNull()
        ->and($circuit->changed_by)->toBe('admin@agelto.ru')
        ->and($this->breaker->admits($this->platformId, SourceStage::Listing))->toBeTrue()
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::ManualPause)->value('actor'))->toBe('admin@agelto.ru')
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::ManualResume)->value('actor'))->toBe('admin@agelto.ru');
});

test('счётчики раздельны: сетевой шум не сбрасывает и не прячет счёт структуры', function (): void {
    ($this->fail)(SourceFailureKind::LayoutDrift, SourceStage::Detail);
    ($this->fail)(SourceFailureKind::SourceUnavailable, SourceStage::Detail);
    $circuit = $this->circuits->forStage($this->platformId, SourceStage::Detail);

    expect($circuit->consecutive_layout_failures)->toBe(1)
        ->and($circuit->consecutive_failures)->toBe(1);

    ($this->fail)(SourceFailureKind::LayoutDrift, SourceStage::Detail);

    expect($this->circuits->forStage($this->platformId, SourceStage::Detail)->status())->toBe(SourceStageStatus::Held);
});

test('404 по умолчанию — только лог: ни журнала, ни счёта, этап работает', function (): void {
    Log::spy();

    foreach (range(1, 5) as $ignored) {
        expect(($this->fail)(SourceFailureKind::NotFound))->toBeTrue();
    }

    expect(SourceIncident::query()->count())->toBe(0)
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->consecutive_failures)->toBe(0);
    Log::shouldHaveReceived('log')->with('warning', Mockery::pattern('/^CRAWLER_CSV_NOT_FOUND: /'), Mockery::any())->times(5);
});

test('одна строка конфига делает 404 отказом площадки', function (): void {
    config()->set('crawler.source_control.stages.listing.reactions.'.SourceFailureKind::NotFound->value, SourceFailureReaction::CountTowardWait);
    app()->forgetInstance(SourceControlPolicy::class);
    $reporter = app(SourceFailureReporter::class);
    $failure = new SourceFailure(platformId: $this->platformId, stage: SourceStage::Listing, kind: SourceFailureKind::NotFound, detail: '404');

    $reporter->report($failure);

    expect($reporter->report($failure))->toBeFalse()
        ->and($this->circuits->forStage($this->platformId, SourceStage::Listing)->status())->toBe(SourceStageStatus::Waiting);
});

test('пороги: пустое поле в админке — из конфига, заполненное — перекрывает', function (): void {
    $circuit = $this->circuits->forStage($this->platformId, SourceStage::Listing);
    $circuit->update(['failure_threshold' => null, 'layout_threshold' => 7]);
    $policy = $this->breaker->effectivePolicy($circuit->refresh());
    $defaults = app(SourceControlPolicy::class)->stage(SourceStage::Listing);

    expect($policy->failureThreshold)->toBe($defaults->failureThreshold)
        ->and($policy->layoutThreshold)->toBe(7)
        ->and($policy->retryIntervalMinutes)->toBe(10);
});
