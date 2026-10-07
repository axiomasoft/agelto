<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Crawler\SourceStageStatus;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;
use App\Services\Procurement\DetailDispatcher;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\SourceControl\SourceStageOperations;
use App\Services\Procurement\SourceControl\SuspendedRunResumer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Crawler\SourceControlScenario as S;
use Tests\Support\Doubles\Crawler\ScriptedSourceMethod;

/*
 * Сценарии надёжности: цепочки отказов через настоящие процессоры (fresh-листинг и карточки)
 * со сценарным источником. Проверяются состояние этапа, курсор, сохранность данных и коды
 * событий. Пороги по умолчанию из config/crawler.php (5 / 3 / 10 мин).
 */

beforeEach(function (): void {
    Storage::fake('s3');
    Sleep::fake();
    Queue::fake();
    $this->crawler = Crawler::factory()->create();
    $this->platformId = $this->crawler->registry->platform_id;
    $this->stage = fn (SourceStage $stage = SourceStage::Listing): SourceCircuit => app(SourceCircuitRepository::class)->forStage($this->platformId, $stage);
    $this->codes = fn (): array => SourceIncident::query()->orderBy('id')->pluck('type')->map(fn (CrawlerEventCode $c): string => $c->value)->all();
    $this->fresh = fn (): Run => app(FreshRunProcessor::class)->run($this->crawler);
});

test('пул прокси лёг: 5 отказов → ожидание, прогон на паузе на том же блоке; через 10 мин продолжает, данные целы', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), S::noProxy(), S::noProxy(), S::noProxy(), S::noProxy(), S::noProxy()]));

    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Paused)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Waiting)
        ->and(Procurement::query()->count())->toBe(2)
        // 4 блока пропущены, 5-й (порог) не пропущен — повторится после ожидания
        ->and($run->cursor['blockFrom'])->toBe($source->requestedBlocks[5])
        ->and(array_count_values(($this->codes)()))->toBe([
            'CRAWLER_CSV_PROXY_UNAVAILABLE' => 5, 'CRAWLER_STAGE_WAITING' => 1, 'CRAWLER_RUN_SUSPENDED' => 1,
        ]);

    $source->thenListing([S::rows(2), S::end()]);
    $this->travel(11)->minutes();
    app(SuspendedRunResumer::class)->resumeDue();
    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Running)
        ->and(Procurement::query()->count())->toBe(4)
        ->and(array_slice($source->requestedBlocks, -2, 1))->toBe([$source->requestedBlocks[5]]);
});

test('всплеск 503 ниже порога: блоки пропущены с записью, обход завершён, счётчик сброшен успехом', function (): void {
    S::install(new ScriptedSourceMethod([S::rows(1), S::status(503), S::status(503), S::status(503), S::rows(2), S::end()]));

    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(4)
        ->and(($this->stage)()->consecutive_failures)->toBe(0)
        ->and(($this->codes)())->toBe(array_fill(0, 3, 'CRAWLER_CSV_SOURCE_UNAVAILABLE'));
});

test('смена формата на 3 блоках подряд → остановка до оператора; таймер её не снимает; «Возобновить» продолжает', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), S::drift(1), S::drift(2), S::drift(3)]));

    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Paused)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Held)
        ->and(Procurement::query()->count())->toBe(2)
        ->and(($this->codes)())->toBe(['CRAWLER_CSV_LAYOUT_DRIFT', 'CRAWLER_CSV_LAYOUT_DRIFT', 'CRAWLER_CSV_LAYOUT_DRIFT', 'CRAWLER_STAGE_HELD', 'CRAWLER_RUN_SUSPENDED']);

    $this->travel(2)->days();
    app(SuspendedRunResumer::class)->resumeDue();
    expect(($this->stage)()->status())->toBe(SourceStageStatus::Held);

    $source->thenListing([S::rows(2), S::end()]);
    app(SourceStageOperations::class)->resume(($this->stage)(), 'operator@agelto.ru');
    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(4);
});

test('смесь сети и смены формата: сетевой шум не прячет остановку', function (): void {
    S::install(new ScriptedSourceMethod([S::drift(1), S::status(503), S::drift(2), S::network(), S::drift(3)]));

    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Paused)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Held)
        ->and(($this->stage)()->consecutive_failures)->toBe(2);
});

test('пустое тело CSV: блоки пропускаются, CRITICAL в лог и журнал, обход не останавливается', function (): void {
    Log::spy();
    S::install(new ScriptedSourceMethod([...array_fill(0, 8, S::emptyBody()), S::rows(1), S::end()]));

    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(2)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Running)
        ->and(($this->codes)())->toBe(array_fill(0, 8, 'CRAWLER_CSV_EMPTY'));
    Log::shouldHaveReceived('log')->with('critical', Mockery::pattern('/^CRAWLER_CSV_EMPTY: /'), Mockery::any())->times(8);
});

test('404 на листинге: только лог, журнала нет, поведение не меняется', function (): void {
    Log::spy();
    S::install(new ScriptedSourceMethod([...array_fill(0, 8, S::status(404)), S::rows(1), S::end()]));

    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(SourceIncident::query()->count())->toBe(0)
        ->and(($this->stage)()->consecutive_failures)->toBe(0);
    Log::shouldHaveReceived('log')->with('warning', Mockery::pattern('/^CRAWLER_CSV_NOT_FOUND: /'), Mockery::any())->times(8);
});

test('ошибка нашего кода на листинге: прогон падает, ранее записанные данные не тронуты', function (): void {
    S::install(new ScriptedSourceMethod([S::rows(1), S::bug()]));

    expect(fn () => ($this->fresh)())->toThrow(LogicException::class);

    expect(Procurement::query()->count())->toBe(2)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Running);
});

/* ---------- карточки ---------- */

function scenarioCard(): Procurement
{
    $registry = Registry::query()->findOrFail(test()->crawler->registry_id);
    $registry->update(['settings' => [
        'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
        'card_url_templates' => ['common-info' => 'https://zakupki.gov.ru/epz/order/notice/{type}/view/common-info.html?regNumber={number}'],
    ]]);

    return Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => S::CardNumber,
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
}

test('карточки: 5 сетевых отказов → ожидание, следующая карточка не запрашивается; после ожидания — успех', function (): void {
    $card = scenarioCard();
    $source = S::install(new ScriptedSourceMethod(details: array_fill(0, 5, S::network())));
    $processor = app(DetailAnalysisProcessor::class);

    foreach (range(1, 6) as $ignored) {
        $processor->run(procurement: $card->refresh());
    }

    expect($source->detailCalls)->toBe(5)
        ->and(($this->stage)(SourceStage::Detail)->status())->toBe(SourceStageStatus::Waiting)
        ->and(($this->stage)()->status())->toBe(SourceStageStatus::Running);

    $source->thenDetails([S::card()]);
    $this->travel(11)->minutes();

    expect($processor->run(procurement: $card->refresh()))->toBeTrue()
        ->and($card->refresh()->detail_fetched_at)->not->toBeNull();
});

test('карточки: ошибки нашего кода и 404 не останавливают этап', function (): void {
    $card = scenarioCard();
    $card->update(['detail_attempts' => -100]);
    S::install(new ScriptedSourceMethod(details: [...array_fill(0, 6, S::bug()), ...array_fill(0, 6, S::cardStatus(404))]));

    foreach (range(1, 12) as $ignored) {
        app(DetailAnalysisProcessor::class)->run(procurement: $card->refresh());
    }

    expect(($this->stage)(SourceStage::Detail)->status())->toBe(SourceStageStatus::Running)
        ->and(array_count_values(($this->codes)()))->toBe(['CRAWLER_CARD_CODE_ERROR' => 6]);
});

test('карточки: новая вёрстка на 3 карточках → остановка; попытки карточек не тратятся', function (): void {
    $card = scenarioCard();
    $source = S::install(new ScriptedSourceMethod(details: array_fill(0, 3, S::cardDrift())));

    foreach (range(1, 4) as $ignored) {
        app(DetailAnalysisProcessor::class)->run(procurement: $card->refresh());
    }

    expect($source->detailCalls)->toBe(3)
        ->and($card->refresh()->detail_attempts)->toBe(0)
        ->and(($this->stage)(SourceStage::Detail)->status())->toBe(SourceStageStatus::Held)
        ->and(array_count_values(($this->codes)()))->toBe(['CRAWLER_CARD_LAYOUT_DRIFT' => 3, 'CRAWLER_STAGE_HELD' => 1]);
});

test('находка: тики планировщика во время остановки не плодят «прогон приостановлен»', function (): void {
    S::install(new ScriptedSourceMethod([S::drift(1), S::drift(2), S::drift(3)]));

    foreach (range(1, 5) as $ignored) {
        ($this->fresh)();
    }

    expect(Run::query()->count())->toBe(1)
        ->and(array_count_values(($this->codes)())['CRAWLER_RUN_SUSPENDED'])->toBe(1);
});

test('находка: при ожидании этапа карточек диспетчер не ставит холостые job и не занимает лимит', function (): void {
    $card = scenarioCard();
    ($this->stage)(SourceStage::Detail)->update(['retry_at' => now()->addMinutes(10)]);

    expect(app(DetailDispatcher::class)->dispatchDue(limit: 10))->toBe(0);
    Queue::assertNothingPushed();

    $this->travel(11)->minutes();

    expect(app(DetailDispatcher::class)->dispatchDue(limit: 10))->toBe(1)
        ->and($card->refresh()->detail_fetched_at)->toBeNull();
});
