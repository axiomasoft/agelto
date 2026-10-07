<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceCircuitState;
use App\Enums\Procurement\Crawler\SourceIncidentType;
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
    $this->crawler = Crawler::factory()->for(Registry::factory()->eis())->create();
    $this->controller = app(SourceCircuitController::class);
    $this->platformId = $this->controller->platformIdFor($this->crawler);
});

test('локальный отказ пишет инцидент с курсором и fetch, брейкер остаётся закрытым', function (): void {
    Event::fake([SourceIncidentRecorded::class]);

    $state = $this->controller->recordFailure(
        crawler: $this->crawler,
        run: null,
        kind: ListingFailureKind::SourceBlocked,
        httpStatus: 403,
        fetchId: 42,
        cursor: ['blockFrom' => 501],
        requestParams: ['pageNumber' => 2],
    );

    $incident = SourceIncident::query()->sole();

    expect($state)->toBe(SourceCircuitState::Closed)
        ->and($incident->type)->toBe(SourceIncidentType::BlockSkipped)
        ->and($incident->fetch_id)->toBe(42)
        ->and($incident->http_status)->toBe(403)
        ->and($incident->cursor)->toBe(['blockFrom' => 501])
        ->and($incident->request_params)->toBe(['pageNumber' => 2])
        ->and($incident->failure_kind)->toBe(ListingFailureKind::SourceBlocked);

    Event::assertDispatched(SourceIncidentRecorded::class, fn (SourceIncidentRecorded $event): bool => $event->incidentId === $incident->id);
});

test('порог отказов открывает брейкер из конфигурации и пишет критичный инцидент', function (): void {
    $this->controller->recordFailure(crawler: $this->crawler, run: null, kind: ListingFailureKind::SourceUnavailable, httpStatus: 503);
    $state = $this->controller->recordFailure(crawler: $this->crawler, run: null, kind: ListingFailureKind::SourceUnavailable, httpStatus: 503);

    $circuit = SourceCircuit::query()->where('platform_id', $this->platformId)->sole();

    expect($state)->toBe(SourceCircuitState::Open)
        ->and($circuit->retry_at?->diffInMinutes(now(), absolute: true))->toEqualWithDelta(10, 0.1)
        ->and(SourceIncident::query()->where('type', SourceIncidentType::CircuitOpened)->count())->toBe(1)
        ->and($this->controller->gate($this->crawler)->allowed)->toBeFalse();
});

test('после retry_at гейт даёт пробу (half-open), успешная проба закрывает брейкер', function (): void {
    SourceCircuit::factory()->create([
        'platform_id' => $this->platformId,
        'state' => SourceCircuitState::Open,
        'retry_at' => now()->subMinute(),
        'open_count' => 1,
        'consecutive_failures' => 2,
    ]);

    $decision = $this->controller->gate($this->crawler);

    expect($decision->probe)->toBeTrue()
        ->and(SourceCircuit::query()->sole()->state)->toBe(SourceCircuitState::HalfOpen);

    $this->controller->recordSuccess($this->crawler);

    expect(SourceCircuit::query()->sole()->state)->toBe(SourceCircuitState::Closed)
        ->and(SourceIncident::query()->pluck('type')->all())
        ->toBe([SourceIncidentType::CircuitHalfOpened, SourceIncidentType::CircuitClosed]);
});

test('пороги, изменённые в админке, применяются к следующему отказу', function (): void {
    $this->controller->circuitFor($this->platformId)->update(['failure_threshold' => 1, 'retry_interval_minutes' => 30]);

    $state = $this->controller->recordFailure(crawler: $this->crawler, run: null, kind: ListingFailureKind::UnrecognizedFormat);

    expect($state)->toBe(SourceCircuitState::Open)
        ->and(SourceCircuit::query()->sole()->retry_at?->diffInMinutes(now(), absolute: true))->toEqualWithDelta(30, 0.1);
});

test('оператор ставит паузу и возобновляет сразу, минуя ожидание', function (): void {
    $this->controller->pause(platformId: $this->platformId, actor: 'admin@agelto.ru', reason: 'Разбираемся с прокси.');

    expect($this->controller->gate($this->crawler)->allowed)->toBeFalse();

    $this->controller->recordFailure(crawler: $this->crawler, run: null, kind: ListingFailureKind::ProxyPoolExhausted);
    $this->controller->resume(platformId: $this->platformId, actor: 'admin@agelto.ru');

    $circuit = SourceCircuit::query()->sole();

    expect($circuit->state)->toBe(SourceCircuitState::Closed)
        ->and($circuit->manually_paused)->toBeFalse()
        ->and($circuit->retry_at)->toBeNull()
        ->and($circuit->changed_by)->toBe('admin@agelto.ru')
        ->and($this->controller->gate($this->crawler)->allowed)->toBeTrue()
        ->and(SourceIncident::query()->where('type', SourceIncidentType::ManualResume)->value('actor'))->toBe('admin@agelto.ru');
});
