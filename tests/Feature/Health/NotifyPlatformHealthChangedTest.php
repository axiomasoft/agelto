<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Events\Procurement\PlatformHealthChanged;
use App\Listeners\Procurement\NotifyPlatformHealthChanged;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\TelegramOutbound;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;

test('слушатель передаёт изменение здоровья в существующий Telegram-транспорт', function (): void {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '123456',
        'process.diagnostic_thresholds.alert_antispam_minutes' => 60,
    ]);
    Cache::forget('pipeline_alert:platform_health:999:card_parse:broken');
    PermitFixtures::clean();
    $wire = new WireFixture;
    try {
        new PermitFixtures($wire);
        $profile = TransportFixtures::profile([
            'code' => 'notification.telegram', 'attempts' => 1, 'distinct_routes' => 1, 'delays_ms' => [], 'redirects' => 0,
        ]);
        $origin = $wire->url('/')->origin;
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('telegram'), [
            new SourceTargetDefinition('provider:telegram:notification', 'notification', $origin, [$profile]),
        ]));
        HistoricalNetworkFixtures::synchronize();
        ProxymaCallerFixtures::install($wire);
        config(['services.telegram.api_url' => $origin->value()]);
        app()->instance(TelegramOutbound::class, new TelegramOutbound($wire->policy()));
        $wire->respond('/bottest-token/sendMessage', '{"ok":true}', method: 'POST');

        app(NotifyPlatformHealthChanged::class)->handle(new PlatformHealthChanged(
            platformId: 999,
            checkKind: PlatformCheckKind::CardParse,
            previousVerdict: PlatformCheckVerdict::Ok,
            verdict: PlatformCheckVerdict::Broken,
            checkId: 123,
            message: 'card_parse: sample=4, worst_share=0.250.',
        ));

        $expectedText = implode("\n", [
            '⚠️ <b>[AGELTO ['.mb_strtoupper(config('app.env')).']] Проблемы пайплайна</b>',
            '',
            '• Площадка #999: разбор карточек (якоря и ключевые поля); стало broken. card_parse: sample=4, worst_share=0.250.',
            '',
            'Проверьте Horizon или запустите <code>php artisan process:status</code> / <code>php artisan pipeline:status</code>',
        ]);
        $payload = json_encode(['chat_id' => '123456', 'text' => $expectedText, 'parse_mode' => 'HTML'], JSON_THROW_ON_ERROR);
        $receipts = $wire->receipts();
        expect($receipts)->toHaveCount(1)
            ->and($receipts[0]['body_sha256'])->toBe(hash('sha256', $payload))
            ->and(Cache::has('pipeline_alert:platform_health:999:card_parse:broken'))->toBeTrue();
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});
