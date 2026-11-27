<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Notifications\PipelineAlert;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\Caller\TelegramOutbound;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;

test('PipelineAlert экранирует HTML-спецсимволы и безопасно обрезает длинный текст до 4096 UTF-8 символов', function (): void {
    PermitFixtures::clean();
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '12345',
        'process.diagnostic_thresholds.alert_antispam_minutes' => 60,
    ]);
    Cache::forget('pipeline_alert:html_key');
    Cache::forget('pipeline_alert:long_key');

    $wire = new WireFixture;
    try {
        new PermitFixtures($wire);
        $profile = TransportFixtures::profile([
            'code' => 'notification.telegram',
            'attempts' => 1,
            'distinct_routes' => 1,
            'delays_ms' => [],
            'redirects' => 0,
        ]);
        $origin = $wire->url('/')->origin;
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(
            new ProviderKey('telegram'),
            [new SourceTargetDefinition('provider:telegram:notification', 'notification', $origin, [$profile])],
        ));
        HistoricalNetworkFixtures::synchronize();
        ProxymaCallerFixtures::install($wire);
        config(['services.telegram.api_url' => $origin->value()]);
        app()->instance(TelegramOutbound::class, new TelegramOutbound($wire->policy()));

        $path = '/bottest-token/sendMessage';
        $wire->respond($path, '{"ok":true}', method: 'POST');
        $wire->respond($path, '{"ok":true}', method: 'POST');

        $alert = app(PipelineAlert::class);

        $rawHtmlFinding = 'Сбой <script>&"кавычки"\' и порог >30мин';
        $alert->notify(['html_key' => $rawHtmlFinding]);

        $expectedEscapedText = implode("\n", [
            '⚠️ <b>[AGELTO ['.mb_strtoupper((string) config('app.env')).']] Проблемы пайплайна</b>',
            '',
            '• '.htmlspecialchars($rawHtmlFinding, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '',
            'Проверьте Horizon или запустите <code>php artisan process:status</code> / <code>php artisan pipeline:status</code>',
        ]);
        $expectedHtmlPayload = json_encode([
            'chat_id' => '12345',
            'text' => $expectedEscapedText,
            'parse_mode' => 'HTML',
        ], JSON_THROW_ON_ERROR);

        expect($wire->receipts())->toHaveCount(1)
            ->and($wire->receipts()[0]['body_sha256'])->toBe(hash('sha256', $expectedHtmlPayload));

        // Проверка обрезки >4096 символов на кириллице и спецсимволах без разрыва сущностей и тегов.
        $longFinding = 'Ошибка <tag>&"quote": '.str_repeat('Кириллица<>&" ', 450);
        $alert->notify(['long_key' => $longFinding]);

        expect($wire->receipts())->toHaveCount(2);

        $reflection = new ReflectionMethod(PipelineAlert::class, 'buildMessage');
        $builtLong = $reflection->invoke($alert, [$longFinding]);
        assert(is_string($builtLong));

        $expectedLongPayload = json_encode([
            'chat_id' => '12345',
            'text' => $builtLong,
            'parse_mode' => 'HTML',
        ], JSON_THROW_ON_ERROR);

        expect(mb_strlen($builtLong, 'UTF-8'))->toBeLessThanOrEqual(4096)
            ->and($builtLong)->toContain('⚠️ <b>[AGELTO')
            ->and($builtLong)->toContain('&lt;tag&gt;&amp;&quot;quote&quot;')
            ->and($builtLong)->toContain('<code>php artisan process:status</code>')
            ->and($builtLong)->not->toMatch('/&[a-zA-Z0-9#]*…/u')
            ->and($wire->receipts()[1]['body_sha256'])->toBe(hash('sha256', $expectedLongPayload));
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});

test('PipelineAlert использует атомарный Cache::add, очищает ключ при ошибке отправки и логирует exception_class', function (): void {
    Log::spy();
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '12345',
        'services.telegram.api_url' => ['invalid_non_string_url'],
        'process.diagnostic_thresholds.alert_antispam_minutes' => 60,
    ]);
    Cache::forget('pipeline_alert:atomic_fail');
    Cache::forget('pipeline_alert:atomic_preexisting');

    $alert = app(PipelineAlert::class);
    $alert->notify(['atomic_fail' => 'Проверка <ошибки> & отката кэша']);

    // При сбое отправки ключ должен быть удалён через Cache::forget, чтобы следующая попытка прошла.
    expect(Cache::has('pipeline_alert:atomic_fail'))->toBeFalse();

    Log::shouldHaveReceived('error')->withArgs(
        static fn (string $message, array $context): bool => $message === 'PipelineAlert: не удалось отправить уведомление'
            && ($context['exception_class'] ?? null) === InvalidArgumentException::class
            && str_contains((string) ($context['message'] ?? ''), '&lt;ошибки&gt; &amp; отката кэша'),
    )->once();

    // Если ключ уже занят (Cache::add вернул false), повторная отправка не выполняется.
    Cache::put('pipeline_alert:atomic_preexisting', true, now()->addMinutes(60));
    $alert->notify(['atomic_preexisting' => 'Повторная находка']);

    Log::shouldHaveReceived('error')->once();
});

test('команда process:diagnostics запланирована каждые пять минут без перекрытий с проверкой схемы', function (): void {
    $schedule = app(Schedule::class);
    $before = count($schedule->events());

    require base_path('routes/console.php');

    $events = collect(array_slice($schedule->events(), $before));
    $diagnosticsEvent = $events->first(
        static fn (Event $event): bool => str_contains((string) $event->command, 'process:diagnostics'),
    );

    expect($diagnosticsEvent)->toBeInstanceOf(Event::class)
        ->and($diagnosticsEvent->expression)->toBe('*/5 * * * *')
        ->and($diagnosticsEvent->withoutOverlapping)->toBeTrue()
        ->and($diagnosticsEvent->filtersPass(app()))->toBe(Schema::hasTable('prc_process_transitions'));
});
