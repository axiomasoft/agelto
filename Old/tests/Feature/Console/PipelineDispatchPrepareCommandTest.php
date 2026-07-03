<?php

declare(strict_types=1);

use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use Illuminate\Support\Facades\Queue;

test('создаёт анализ по PENDING-вложению и запускает цепочку', function () {
    Queue::fake();

    $link = Link::factory()->pending()->create();
    $attachment = LinkAttachment::factory()->for($link)->create();

    $this->artisan('app:pipeline:dispatch-prepare')->assertSuccessful();

    // Создан анализ по вложению, ссылка ушла в обработку, цепочка стартовала.
    expect($attachment->documentAnalyses()->count())->toBe(1)
        ->and($link->refresh()->status)->toBe(CrawlingStatus::PROCESSING);

    Queue::assertPushed(PrepareDocumentJob::class);
});

test('идемпотентен: вложение с анализом не дублируется', function () {
    Queue::fake();

    $link = Link::factory()->pending()->create();
    $attachment = LinkAttachment::factory()->for($link)->create();
    // По вложению уже есть анализ — повторно обрабатывать нечего.
    DocumentAnalysis::factory()->forAttachment($attachment)->primaryAnalyzed()->create();

    $this->artisan('app:pipeline:dispatch-prepare')->assertSuccessful();

    // Дубль анализа не создан, цепочка повторно не запущена.
    expect($attachment->documentAnalyses()->count())->toBe(1);
    Queue::assertNotPushed(PrepareDocumentJob::class);
});
