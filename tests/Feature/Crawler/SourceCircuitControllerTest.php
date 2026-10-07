<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Events\Procurement\SourceIncidentRecorded;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\SourceControl\SourceCircuitController;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('crawler.source_circuit.failure_threshold', 2);
    config()->set('crawler.source_circuit.retry_interval_minutes', 10);
    $this->controller = app(SourceCircuitController::class);
    $this->platformId = $this->controller->platformIdFor(Crawler::factory()->for(Registry::factory()->eis())->create());
});

test('отказ ниже порога только считается: этап работает, инцидента об ожидании нет', function (): void {
    $waiting = $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceBlocked);

    expect($waiting)->toBeFalse()
        ->and($this->controller->circuitFor($this->platformId, SourceStage::Listing)->consecutive_failures)->toBe(1)
        ->and(SourceIncident::query()->count())->toBe(0);
});

test('порог: этап уходит в ожидание на интервал, критичный инцидент и событие', function (): void {
    Event::fake([SourceIncidentRecorded::class]);

    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable);
    $waiting = $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable);

    $circuit = $this->controller->circuitFor($this->platformId, SourceStage::Listing);

    expect($waiting)->toBeTrue()
        ->and($circuit->retry_at?->diffInMinutes(now(), absolute: true))->toEqualWithDelta(10, 0.1)
        ->and($circuit->consecutive_failures)->toBe(0)
        ->and($this->controller->admits($this->platformId, SourceStage::Listing))->toBeFalse()
        ->and(SourceIncident::query()->sole()->type)->toBe(SourceIncidentType::CircuitOpened);

    Event::assertDispatched(SourceIncidentRecorded::class);
});

test('после ожидания этап просто работает снова — без пробных запросов', function (): void {
    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable);
    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable);

    $this->travel(11)->minutes();

    expect($this->controller->admits($this->platformId, SourceStage::Listing))->toBeTrue()
        // один отказ после ожидания снова не уводит в ожидание: счётчик начат заново
        ->and($this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable))->toBeFalse();
});

test('успех сбрасывает счётчик; ошибки строк не считаются', function (): void {
    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable);
    $this->controller->recordSuccess($this->platformId, SourceStage::Listing);
    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::RowErrors);

    expect($this->controller->circuitFor($this->platformId, SourceStage::Listing)->consecutive_failures)->toBe(0);
});

test('этапы независимы: ожидание карточек не останавливает листинг', function (): void {
    $this->controller->recordFailure($this->platformId, SourceStage::Detail, SourceFailureKind::LayoutDrift);
    $this->controller->recordFailure($this->platformId, SourceStage::Detail, SourceFailureKind::LayoutDrift);

    expect($this->controller->admits($this->platformId, SourceStage::Detail))->toBeFalse()
        ->and($this->controller->admits($this->platformId, SourceStage::Listing))->toBeTrue();
});

test('выключенный брейкер не уходит в ожидание, ручная пауза действует всегда', function (): void {
    $circuit = $this->controller->circuitFor($this->platformId, SourceStage::Listing);
    $circuit->update(['enabled' => false]);

    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable);
    expect($this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::SourceUnavailable))->toBeFalse();

    $this->controller->pause($circuit->refresh(), 'admin@agelto.ru', 'Разбираемся с прокси.');
    expect($this->controller->admits($this->platformId, SourceStage::Listing))->toBeFalse();
});

test('пороги из админки применяются к следующему отказу', function (): void {
    $this->controller->circuitFor($this->platformId, SourceStage::Listing)->update(['failure_threshold' => 1, 'retry_interval_minutes' => 30]);

    expect($this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::InvalidContent))->toBeTrue()
        ->and(SourceCircuit::query()->sole()->retry_at?->diffInMinutes(now(), absolute: true))->toEqualWithDelta(30, 0.1);
});

test('оператор: пауза и «возобновить сейчас» снимает паузу и ожидание', function (): void {
    $circuit = $this->controller->circuitFor($this->platformId, SourceStage::Listing);
    $this->controller->pause($circuit, 'admin@agelto.ru');
    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::ProxyUnavailable);
    $this->controller->recordFailure($this->platformId, SourceStage::Listing, SourceFailureKind::ProxyUnavailable);

    $this->controller->resume($circuit->refresh(), 'admin@agelto.ru');
    $circuit->refresh();

    expect($circuit->manually_paused)->toBeFalse()
        ->and($circuit->retry_at)->toBeNull()
        ->and($circuit->changed_by)->toBe('admin@agelto.ru')
        ->and($this->controller->admits($this->platformId, SourceStage::Listing))->toBeTrue()
        ->and(SourceIncident::query()->where('type', SourceIncidentType::ManualResume)->value('actor'))->toBe('admin@agelto.ru');
});
