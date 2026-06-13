<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Models\Platform;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineAlert;
use App\Notifications\PipelineAlertNotification;
use App\Notifications\PipelineFailedNotification;
use App\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use App\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
use App\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use App\Process\EstimateAnalysis\Jobs\EstimateAnalysisJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function makeLink(CrawlingStatus $status, array $attributes = []): Link
{
    $platform = Platform::query()->create([
        'name' => 'ЕИС',
        'code' => 'eis-'.uniqid(),
        'url' => 'https://zakupki.gov.ru',
    ]);

    return Link::query()->create(array_merge([
        'platform_id' => $platform->id,
        'reg_number' => (string) random_int(1_000_000, 9_999_999),
        'detail_url' => 'https://zakupki.gov.ru/order/'.uniqid(),
        'status' => $status,
    ], $attributes));
}

test('pipeline:status выводит обе таблицы стадий без ошибок', function () {
    makeLink(CrawlingStatus::PENDING);
    DocumentAnalysis::query()->create([
        'source' => 'https://example.com/a.pdf',
        'status' => AnalysisStatus::Completed->value,
    ]);

    $this->artisan('app:pipeline:status')
        ->assertSuccessful();
});

test('pipeline:stuck-check молчит, когда зависших задач нет', function () {
    Notification::fake();

    $this->artisan('app:pipeline:stuck-check')
        ->expectsOutputToContain('Зависших задач не обнаружено.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('pipeline:stuck-check шлёт алерт при зависшей ссылке', function () {
    Notification::fake();

    // Ссылка в PROCESSING, висящая дольше порога зависания.
    $link = makeLink(CrawlingStatus::PROCESSING);
    $link->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();

    $this->artisan('app:pipeline:stuck-check')
        ->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

test('pipeline:retry-failed-links перезапускает провал первичного анализа только с этой стадии', function () {
    Queue::fake();

    $link = makeLink(CrawlingStatus::PROCESSING);
    $attachment = LinkAttachment::query()->create([
        'link_id' => $link->id,
        'external_id' => 'att-1',
        'name' => 'smeta.xlsx',
        'url' => 'https://example.com/smeta.xlsx',
    ]);
    $analysis = $attachment->documentAnalyses()->create([
        'source' => 'https://example.com/smeta.xlsx',
        'status' => AnalysisStatus::PrimaryAnalysisFailed->value,
    ]);

    $this->artisan('app:pipeline:retry-failed-links')->assertSuccessful();

    // Claim перевёл анализ в Prepared; диспатчен только PrimaryAnalysisJob (без повторной подготовки).
    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Prepared);
    Queue::assertPushed(PrimaryAnalysisJob::class);
    Queue::assertNotPushed(PrepareDocumentJob::class);
});

test('pipeline:retry-failed-links перезапускает провал анализа сметы без повторного скрининга', function () {
    Queue::fake();

    $link = makeLink(CrawlingStatus::PROCESSING);
    $attachment = LinkAttachment::query()->create([
        'link_id' => $link->id,
        'external_id' => 'att-2',
        'name' => 'smeta.xlsx',
        'url' => 'https://example.com/smeta.xlsx',
    ]);
    $analysis = $attachment->documentAnalyses()->create([
        'source' => 'https://example.com/smeta.xlsx',
        'status' => AnalysisStatus::EstimateAnalysisFailed->value,
    ]);

    $this->artisan('app:pipeline:retry-failed-links')->assertSuccessful();

    // Хвост — только EstimateAnalysisJob; повторного скрининга/подготовки нет.
    expect($analysis->refresh()->status)->toBe(AnalysisStatus::PrimaryAnalyzed);
    Queue::assertPushed(EstimateAnalysisJob::class);
    Queue::assertNotPushed(PrimaryAnalysisJob::class);
    Queue::assertNotPushed(PrepareDocumentJob::class);
});

test('pipeline:retry-failed-links не трогает dead-letter (failed_permanently)', function () {
    Queue::fake();

    $analysis = DocumentAnalysis::query()->create([
        'source' => 'https://example.com/x.xlsx',
        'status' => AnalysisStatus::EstimateAnalysisFailed->value,
        'failed_permanently' => true,
    ]);

    $this->artisan('app:pipeline:retry-failed-links')->assertSuccessful();

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::EstimateAnalysisFailed);
    Queue::assertNothingPushed();
});

test('recordFailure инкрементит попытки, ставит backoff, а на исчерпании — dead-letter', function () {
    $analysis = DocumentAnalysis::query()->create([
        'source' => 'https://example.com/y.xlsx',
        'status' => AnalysisStatus::PrimaryAnalyzing->value,
    ]);

    $analysis->recordFailure(AnalysisStatus::PrimaryAnalysisFailed, 'boom', maxAttempts: 2);
    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::PrimaryAnalysisFailed)
        ->and($analysis->attempts)->toBe(1)
        ->and($analysis->failed_permanently)->toBeFalse()
        ->and($analysis->next_retry_at)->not->toBeNull();

    $analysis->recordFailure(AnalysisStatus::PrimaryAnalysisFailed, 'boom2', maxAttempts: 2);
    $analysis->refresh();
    expect($analysis->attempts)->toBe(2)
        ->and($analysis->failed_permanently)->toBeTrue()
        ->and($analysis->next_retry_at)->toBeNull();
});

test('Link.status агрегируется из анализов: терминальный успех → COMPLETED', function () {
    $link = makeLink(CrawlingStatus::PROCESSING);
    $attachment = LinkAttachment::query()->create([
        'link_id' => $link->id,
        'external_id' => 'att-3',
        'name' => 'smeta.xlsx',
        'url' => 'https://example.com/smeta.xlsx',
    ]);
    $analysis = $attachment->documentAnalyses()->create([
        'source' => 'https://example.com/smeta.xlsx',
        'status' => AnalysisStatus::EstimateAnalyzing->value,
    ]);

    // Переход в терминальный статус пересчитывает агрегат ссылки.
    $analysis->transitionStatus(AnalysisStatus::Completed);

    expect($link->refresh()->status)->toBe(CrawlingStatus::COMPLETED);
});

test('pipeline:dispatch-prepare создаёт анализ по PENDING-вложению и запускает цепочку', function () {
    Queue::fake();

    $link = makeLink(CrawlingStatus::PENDING);
    $attachment = LinkAttachment::query()->create([
        'link_id' => $link->id,
        'external_id' => 'att-1',
        'name' => 'smeta.xlsx',
        'url' => 'https://example.com/smeta.xlsx',
    ]);

    $this->artisan('app:pipeline:dispatch-prepare')->assertSuccessful();

    // Создан анализ по вложению, ссылка ушла в обработку, цепочка стартовала.
    expect($attachment->documentAnalyses()->count())->toBe(1)
        ->and($link->refresh()->status)->toBe(CrawlingStatus::PROCESSING);

    Queue::assertPushed(PrepareDocumentJob::class);
});

test('pipeline:dispatch-prepare идемпотентен: вложение с анализом не дублируется', function () {
    Queue::fake();

    $link = makeLink(CrawlingStatus::PENDING);
    $attachment = LinkAttachment::query()->create([
        'link_id' => $link->id,
        'external_id' => 'att-1',
        'name' => 'smeta.xlsx',
        'url' => 'https://example.com/smeta.xlsx',
    ]);
    // По вложению уже есть анализ — повторно обрабатывать нечего.
    $attachment->documentAnalyses()->create([
        'source' => 'https://example.com/smeta.xlsx',
        'status' => AnalysisStatus::PrimaryAnalyzed->value,
    ]);

    $this->artisan('app:pipeline:dispatch-prepare')->assertSuccessful();

    // Дубль анализа не создан, цепочка повторно не запущена.
    expect($attachment->documentAnalyses()->count())->toBe(1);
    Queue::assertNotPushed(PrepareDocumentJob::class);
});

test('acquireLease берёт свободную лизу и блокирует повторный захват, releaseLease освобождает', function () {
    $analysis = DocumentAnalysis::query()->create([
        'source' => 'https://example.com/lease.xlsx',
        'status' => AnalysisStatus::Preparing->value,
    ]);

    expect($analysis->acquireLease('worker-a'))->toBeTrue();

    // Пока лиза держится — другой воркер взять её не может.
    $other = DocumentAnalysis::query()->find($analysis->id);
    expect($other->acquireLease('worker-b'))->toBeFalse();

    $analysis->releaseLease();
    expect($other->acquireLease('worker-b'))->toBeTrue();
});

test('истёкшая лиза перезахватывается (stuck-recovery)', function () {
    $analysis = DocumentAnalysis::query()->create([
        'source' => 'https://example.com/lease2.xlsx',
        'status' => AnalysisStatus::Preparing->value,
        'locked_by' => 'dead-worker',
        'locked_until' => now()->subMinute(),
    ]);

    expect($analysis->acquireLease('worker-c'))->toBeTrue();
});

test('PrimaryAnalysisJob не обрабатывает запись под чужой активной лизой', function () {
    $analysis = DocumentAnalysis::query()->create([
        'source' => 'https://example.com/leased.xlsx',
        'status' => AnalysisStatus::Prepared->value,
        'locked_by' => 'other',
        'locked_until' => now()->addMinutes(5),
    ]);

    // Лиза занята — handle вернётся до вызова процессора (Gemini не дёргается).
    (new PrimaryAnalysisJob($analysis->id))->handle(app(PrimaryAnalysisProcessor::class));

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::Prepared)
        ->and($analysis->locked_by)->toBe('other');
});

test('TelegramChannel при отсутствии конфига пишет алерт в лог, а не молчит', function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
    Log::spy();

    (new TelegramChannel())->send(notifiable: null, notification: new PipelineAlertNotification(message: 'тест-алерт'));

    Log::shouldHaveReceived('warning')->once();
});

test('pipeline:stuck-check алертит про backlog вложений без анализа (разрыв конвейера)', function () {
    Notification::fake();

    $link = makeLink(CrawlingStatus::PENDING);
    $attachment = LinkAttachment::query()->create([
        'link_id' => $link->id,
        'external_id' => 'att-backlog',
        'name' => 'smeta.xlsx',
        'url' => 'https://example.com/smeta.xlsx',
    ]);
    // Вложение без анализа висит дольше порога backlog — конвейер должен был разобрать.
    $attachment->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

test('PipelineAlert троттлит: на стадию не больше одного алерта в окне', function () {
    Cache::flush();
    Notification::fake();

    PipelineAlert::failure('Primary Analysis', 'Job', 1, 'boom', 1, 5);
    PipelineAlert::failure('Primary Analysis', 'Job', 2, 'boom2', 2, 5);
    PipelineAlert::failure('Document Prepare', 'Job', 3, 'boom3', 1, 3);

    // Две Primary схлопнулись в один алерт, Document Prepare — отдельный.
    Notification::assertSentOnDemandTimes(PipelineFailedNotification::class, 2);
});

test('AppLogger пишет в канал pipeline, канал — JSON daily', function () {
    // Запись не должна падать — канал должен быть сконфигурирован.
    app(App\Services\AppLogger::class)->info('тест', App\Enums\LogOutput::File, ['stage' => 'crawling']);

    expect(config('logging.channels.pipeline.driver'))->toBe('daily')
        ->and(config('logging.channels.pipeline.formatter'))->toBe(Monolog\Formatter\JsonFormatter::class);
});
