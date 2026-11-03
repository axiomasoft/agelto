<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Events\Procurement\PlatformHealthChanged;
use App\Listeners\Procurement\NotifyPlatformHealthChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('слушатель передаёт изменение здоровья в существующий Telegram-транспорт', function (): void {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '123456',
        'process.diagnostic_thresholds.alert_antispam_minutes' => 60,
    ]);
    Cache::forget('pipeline_alert:platform_health:999:card_parse:broken');
    Http::fake();

    app(NotifyPlatformHealthChanged::class)->handle(new PlatformHealthChanged(
        platformId: 999,
        checkKind: PlatformCheckKind::CardParse,
        previousVerdict: PlatformCheckVerdict::Ok,
        verdict: PlatformCheckVerdict::Broken,
        checkId: 123,
        message: 'card_parse: sample=4, worst_share=0.250.',
    ));

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'стало broken')
        && str_contains((string) $request['text'], 'якоря и ключевые поля')
        && str_contains((string) $request['text'], 'sample=4'));
});
