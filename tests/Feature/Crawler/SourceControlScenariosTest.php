<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\ProcurementStatus;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use App\Services\Procurement\DetailDispatcher;
use App\Services\Procurement\DetailRefetchSelector;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\Network\AcquisitionEligibility;
use App\Services\Procurement\Network\LegacyAcquisitionControl;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Crawler\SourceControlScenario as S;
use Tests\Support\Doubles\Crawler\ScriptedSourceMethod;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

/*
 * Сценарии надёжности: цепочки отказов через настоящие процессоры (fresh-листинг и карточки)
 * со сценарным источником. Проверяются состояние этапа, курсор, сохранность данных и коды
 * событий. Пороги по умолчанию из config/crawler.php (5 / 3 / 10 мин).
 */

beforeEach(function (): void {
    LegacyBusinessNetwork::install();
    Storage::fake('s3');
    Sleep::fake();
    Queue::fake();
    $this->crawler = Crawler::factory()->create();
    $this->platformId = $this->crawler->registry->platform_id;
    $this->eligible = fn (StageCode $stage = StageCode::Listing): bool => ! in_array($this->platformId, app(AcquisitionEligibility::class)->excludedPlatforms($stage), true);
    $this->codes = fn (): array => SourceIncident::query()->orderBy('id')->pluck('type')->map(fn (CrawlerEventCode $c): string => $c->value)->all();
    $this->fresh = fn (): Run => app(FreshRunProcessor::class)->run($this->crawler);
    $this->attempts = function (int $count): Run {
        foreach (range(1, $count) as $attempt) {
            if ($attempt > 1) {
                $this->travel(61)->minutes();
            }
            $run = ($this->fresh)();
        }

        return $run;
    };
});

test('пул прокси лёг: 5 отказов → ожидание, прогон на паузе на том же блоке; через 10 мин продолжает, данные целы', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), S::noProxy(), S::noProxy(), S::noProxy(), S::noProxy(), S::noProxy()]));

    $run = ($this->attempts)(5);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and(($this->eligible)())->toBeTrue()
        ->and($run->attempts)->toBe(0)
        ->and(Procurement::query()->count())->toBe(2)
        ->and($run->cursor['blockFrom'])->toBe(501)
        ->and($source->requestedBlocks)->toBe([1, 501, 501, 501, 501, 501])
        ->and(array_count_values(($this->codes)()))->toBe([
            'CRAWLER_CSV_PROXY_UNAVAILABLE' => 5, 'CRAWLER_RUN_SUSPENDED' => 5, 'CRAWLER_RUN_RESUMED' => 4,
        ]);

    $source->thenListing([S::rows(2), S::end()]);
    $this->travel(61)->minutes();
    Artisan::call('crawler:recover-stale');
    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(($this->eligible)())->toBeTrue()
        ->and(Procurement::query()->count())->toBe(4)
        ->and(array_slice($source->requestedBlocks, -2, 1))->toBe([$source->requestedBlocks[5]]);
});

test('всплеск 503 ниже порога: тот же блок повторён после ожидания, обход завершён без пропусков', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), S::status(503), S::status(503), S::status(503), S::rows(2), S::end()]));

    $run = ($this->attempts)(4);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(4)
        ->and(DB::table('prc_source_circuits')->count())->toBe(0)
        ->and($source->requestedBlocks)->toBe([1, 501, 501, 501, 501, 1001])
        ->and(array_count_values(($this->codes)()))->toBe(['CRAWLER_CSV_SOURCE_UNAVAILABLE' => 3, 'CRAWLER_RUN_SUSPENDED' => 3, 'CRAWLER_RUN_RESUMED' => 3]);
});

test('смена формата на 3 попытках одного блока → остановка до оператора; таймер её не снимает; «Возобновить» продолжает', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), S::drift(1), S::drift(2), S::drift(3)]));

    $run = ($this->attempts)(3);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->failed_permanently)->toBeTrue()
        ->and(Procurement::query()->count())->toBe(2)
        ->and($source->requestedBlocks)->toBe([1, 501, 501, 501])
        ->and(array_count_values(($this->codes)()))->toBe(['CRAWLER_CSV_LAYOUT_DRIFT' => 3, 'CRAWLER_RUN_SUSPENDED' => 3, 'CRAWLER_RUN_RESUMED' => 2]);

    $this->travel(2)->days();
    Artisan::call('crawler:recover-stale');
    expect($run->fresh()->failed_permanently)->toBeTrue();

    $source->thenListing([S::rows(2), S::end()]);
    app(StartCrawlerRunAction::class)->resume($this->crawler, actorId: User::factory()->create()->id, reason: 'Проверен формат сохранённого блока');
    $run = ($this->fresh)();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(4);
});

test('смесь сети и смены формата: сетевой шум не прячет остановку', function (): void {
    S::install(new ScriptedSourceMethod([S::drift(1), S::status(503), S::drift(2), S::network(), S::drift(3)]));

    $run = ($this->attempts)(5);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->failed_permanently)->toBeTrue()
        ->and($run->attempts)->toBe(3)
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
});

test('пустое тело CSV: повторяем тот же блок с задержкой до восстановления, CRITICAL в лог и журнал', function (): void {
    Log::spy();
    $source = S::install(new ScriptedSourceMethod([...array_fill(0, 8, S::emptyBody()), S::rows(1), S::end()]));

    foreach (range(1, 9) as $attempt) {
        $run = ($this->fresh)();
        if ($run->failed_permanently) {
            app(StartCrawlerRunAction::class)->resume($this->crawler, actorId: User::factory()->create()->id, reason: 'Проверен пустой ответ, разрешён ограниченный повтор');
        }
        $this->travel(61)->minutes();
    }

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(2)
        ->and(($this->eligible)())->toBeTrue()
        ->and($source->requestedBlocks)->toBe([...array_fill(0, 9, 1), 501])
        ->and(array_count_values(($this->codes)()))->toBe(['CRAWLER_CSV_EMPTY' => 8, 'CRAWLER_RUN_SUSPENDED' => 8, 'CRAWLER_RUN_RESUMED' => 10]);
    Log::shouldHaveReceived('log')->with('critical', Mockery::pattern('/^CRAWLER_CSV_EMPTY: /'), Mockery::any())->times(8);
});

test('404 на листинге: ответ не означает конец списка, прогон повторяет сохранённый блок', function (): void {
    Log::spy();
    $source = S::install(new ScriptedSourceMethod([...array_fill(0, 8, S::status(404)), S::rows(1), S::end()]));

    $run = ($this->attempts)(9);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::CsvNotFound)->count())->toBe(0)
        ->and($source->requestedBlocks)->toBe([...array_fill(0, 9, 1), 501])
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
    Log::shouldHaveReceived('log')->with('warning', Mockery::pattern('/^CRAWLER_CSV_NOT_FOUND: /'), Mockery::any())->times(8);
});

test('баг нашего кода на листинге: сохраняем курсор и после исправления повторяем тот же блок', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), S::bug(), S::rows(2), S::end()]));

    $run = ($this->attempts)(2);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(4)
        ->and($source->requestedBlocks)->toBe([1, 501, 501, 1001])
        ->and(($this->codes)())->toBe(['CRAWLER_CSV_CODE_ERROR', 'CRAWLER_RUN_SUSPENDED', 'CRAWLER_RUN_RESUMED'])
        ->and(DB::table('prc_source_circuits')->count())->toBe(0);
});

test('повторяющийся баг: 50 неуспешных попыток останавливают этап без продвижения курсора', function (): void {
    $source = S::install(new ScriptedSourceMethod([S::rows(1), ...array_fill(0, 50, S::bug())]));

    $run = ($this->attempts)(50);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->status)->not->toBe(RunStatus::Failed)
        ->and($run->failed_permanently)->toBeTrue()
        ->and(Procurement::query()->count())->toBe(2)
        ->and($source->requestedBlocks)->toBe([1, ...array_fill(0, 50, 501)])
        ->and(array_count_values(($this->codes)()))->toMatchArray(['CRAWLER_CSV_CODE_ERROR' => 50, 'CRAWLER_RUN_SUSPENDED' => 50]);
});

test('сбой вне обработки блока: прогон не провален, а на паузе с тем же курсором и CRITICAL-событием', function (): void {
    S::install(new ScriptedSourceMethod([S::rows(1), S::end()]));
    $run = Run::factory()->for($this->crawler)->create(['status' => RunStatus::Running]);

    app(LegacyAcquisitionControl::class)->interrupted($run, new RuntimeException('db gone'));
    app(LegacyAcquisitionControl::class)->interrupted($run, ExecutionLeaseLost::noLongerOwned());

    expect($run->refresh()->status)->toBe(RunStatus::Paused)
        ->and($run->stats['stop_reason'])->toBe('interrupted')
        ->and($run->last_error)->toBe('db gone')
        ->and($run->last_error_at)->not->toBeNull()
        ->and(($this->codes)())->toBe(['CRAWLER_RUN_INTERRUPTED']);
});

test('сбой после отмены прогона: статус не меняется, ложного «поставлен на паузу» нет', function (): void {
    $run = Run::factory()->for($this->crawler)->create(['status' => RunStatus::Cancelled]);

    app(LegacyAcquisitionControl::class)->interrupted($run, new RuntimeException('late failure'));

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and(($this->codes)())->toBe([]);
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

    foreach (range(1, 5) as $ignored) {
        $processor->run(procurement: $card->refresh());
    }
    $target = DB::table('prc_source_targets')->where('platform_id', $this->platformId)->where('capability', 'detail')->sole();
    DB::table('prc_target_health')->where('target_id', $target->id)->update(['state' => 'open', 'open_until' => now()->addMinutes(10)]);
    $processor->run(procurement: $card->refresh());

    expect($source->detailCalls)->toBe(5)
        ->and(($this->eligible)(StageCode::Detail))->toBeFalse()
        ->and($card->refresh()->detail_attempts)->toBe(0)
        ->and(($this->eligible)())->toBeTrue();

    $source->thenDetails([S::card()]);
    DB::table('prc_target_health')->where('target_id', $target->id)->update(['open_until' => now()->subMinute()]);

    expect($processor->run(procurement: $card->refresh()))->toBeTrue()
        ->and($card->refresh()->detail_fetched_at)->not->toBeNull();
});

test('карточки: ошибки нашего кода не останавливают этап', function (): void {
    $card = scenarioCard();
    $card->update(['detail_attempts' => -100]);
    S::install(new ScriptedSourceMethod(details: array_fill(0, 6, S::bug())));

    foreach (range(1, 6) as $ignored) {
        app(DetailAnalysisProcessor::class)->run(procurement: $card->refresh());
    }

    expect(($this->eligible)(StageCode::Detail))->toBeTrue()
        ->and(array_count_values(($this->codes)()))->toBe(['CRAWLER_CARD_CODE_ERROR' => 6]);
});

test('временная 404 карточки повторяется с backoff и восстанавливается', function (): void {
    Log::spy();
    $card = scenarioCard();
    $source = S::install(new ScriptedSourceMethod(details: [S::cardStatus(404), S::card()]));

    expect(app(DetailAnalysisProcessor::class)->run(procurement: $card))->toBeFalse();
    $card->refresh();

    expect($card->detail_failed)->toBeFalse()
        ->and($card->detail_attempts)->toBe(1)
        ->and($card->detail_next_retry_at)->not->toBeNull()
        ->and(SourceIncident::query()->count())->toBe(0)
        ->and(app(DetailRefetchSelector::class)->due(limit: 10)->count())->toBe(0)
        ->and($source->detailCalls)->toBe(1);
    Log::shouldHaveReceived('log')->with('warning', Mockery::pattern('/^CRAWLER_CARD_NOT_FOUND: /'), Mockery::any())->once();

    $this->travelTo($card->detail_next_retry_at->copy()->addSecond());
    expect(app(DetailRefetchSelector::class)->due(limit: 10)->pluck('id')->all())->toContain($card->id)
        ->and(app(DetailAnalysisProcessor::class)->run(procurement: $card))->toBeTrue()
        ->and($card->refresh()->detail_fetched_at)->not->toBeNull()
        ->and($card->detail_attempts)->toBe(0);
});

test('карточки: новая вёрстка на 3 карточках → остановка; попытки карточек не тратятся', function (): void {
    PermitFixtures::clean();
    $wire = new WireFixture;
    try {
        $registry = CallerFixtures::platform($wire);
        config(['crawler.documents_allowed_hosts' => ['fixture.test']]);
        $registry->update(['settings' => [
            'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
            'card_url_templates' => ['ea20' => [
                'common-info' => $wire->url('/common-info?regNumber={regNumber}')->reveal(),
                'documents' => $wire->url('/documents?regNumber={regNumber}')->reveal(),
                'event-journal' => $wire->url('/event-journal?regNumber={regNumber}')->reveal(),
            ]],
        ]]);
        $cards = [];
        foreach (range(1, 3) as $index) {
            $number = '033820000912500000'.$index;
            foreach (['common-info', 'documents', 'event-journal'] as $tab) {
                $wire->respond('/'.$tab, '<html><body>'.$number.' новая вёрстка</body></html>', headers: ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            $card = Procurement::factory()->create(['registry_id' => $registry->id, 'registry_number' => $number,
                'procedure_type_raw' => 'Электронный аукцион', 'status' => ProcurementStatus::Bidding]);
            expect(app(DetailAnalysisProcessor::class)->run($card))->toBeFalse();
            $cards[] = $card;
        }
        expect(DB::table('prc_adapter_health')->where('capability', 'detail')->sole()->state)->toBe('held');
        app(DetailAnalysisProcessor::class)->run($cards[0]->fresh());
        expect($wire->receipts())->toHaveCount(9);
        foreach ($cards as $card) {
            expect($card->fresh()->detail_attempts)->toBe(0)->and($card->fresh()->detail_fetched_at)->toBeNull();
        }
        expect(SourceIncident::query()->where('type', CrawlerEventCode::CardLayoutDrift)->count())->toBe(3);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
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
    $target = DB::table('prc_source_targets')->where('platform_id', $this->platformId)->where('capability', 'detail')->sole();
    DB::table('prc_target_health')->where('target_id', $target->id)->update(['state' => 'open', 'open_until' => now()->addMinutes(10)]);

    expect(app(DetailDispatcher::class)->dispatchDue(limit: 10))->toBe(0);
    Queue::assertNothingPushed();

    DB::table('prc_target_health')->where('target_id', $target->id)->update(['open_until' => now()->subMinute()]);

    expect(app(DetailDispatcher::class)->dispatchDue(limit: 10))->toBe(1)
        ->and($card->refresh()->detail_fetched_at)->toBeNull();
});
