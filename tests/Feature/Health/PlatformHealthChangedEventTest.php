<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Events\Procurement\PlatformHealthChanged;
use App\Listeners\Procurement\NotifyPlatformHealthChanged;
use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Health\PlatformHealthChecker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\AdapterObservationFixtures;

function platformHealthCheck(Platform $platform, PlatformCheckVerdict $verdict, $checkedAt): PlatformCheck
{
    return PlatformCheck::factory()->create([
        'platform_id' => $platform->id,
        'check_kind' => PlatformCheckKind::CardParse,
        'verdict' => $verdict,
        'checked_at' => $checkedAt,
    ]);
}

function platformForPlatformHealthCheck(): Platform
{
    return Platform::factory()->create([
        'uuid' => (string) Str::uuid(),
        'code' => 'eis',
    ]);
}

function runCardPlatformHealthCheck(Platform $platform): void
{
    app(PlatformHealthChecker::class)->run(
        platform: $platform,
        from: now()->subDay(),
        to: now(),
        kind: PlatformCheckKind::CardParse,
    );
}

function procurementForPlatformHealthCheck(Platform $platform, bool $broken): void
{
    $registry = Registry::factory()->create([
        'uuid' => (string) Str::uuid(),
        'platform_id' => $platform->id,
    ]);

    AdapterObservationFixtures::detail($platform->code, $broken ? AdapterVerdict::ContractFailure : AdapterVerdict::Accepted);
}

test('ухудшение здоровья площадки порождает событие с предыдущим вердиктом', function (): void {
    Event::fake();
    $platform = platformForPlatformHealthCheck();
    platformHealthCheck(platform: $platform, verdict: PlatformCheckVerdict::Ok, checkedAt: now()->subHour());
    procurementForPlatformHealthCheck(platform: $platform, broken: true);

    runCardPlatformHealthCheck(platform: $platform);

    Event::assertDispatched(PlatformHealthChanged::class, function (PlatformHealthChanged $event) use ($platform): bool {
        return $event->platformId === $platform->id
            && $event->checkKind === PlatformCheckKind::CardParse
            && $event->previousVerdict === PlatformCheckVerdict::Ok
            && $event->verdict === PlatformCheckVerdict::Broken;
    });
});

test('восстановление здоровья площадки до ok порождает событие', function (): void {
    Event::fake();
    $platform = platformForPlatformHealthCheck();
    platformHealthCheck(platform: $platform, verdict: PlatformCheckVerdict::Broken, checkedAt: now()->subHour());
    procurementForPlatformHealthCheck(platform: $platform, broken: false);

    runCardPlatformHealthCheck(platform: $platform);

    Event::assertDispatched(PlatformHealthChanged::class, function (PlatformHealthChanged $event): bool {
        return $event->previousVerdict === PlatformCheckVerdict::Broken
            && $event->verdict === PlatformCheckVerdict::Ok;
    });
});

test('неизменившийся плохой вердикт внутри окна напоминания не порождает событие', function (): void {
    Event::fake();
    config(['process.diagnostics.repeat_alert_hours' => 24]);
    $platform = platformForPlatformHealthCheck();
    platformHealthCheck(platform: $platform, verdict: PlatformCheckVerdict::Broken, checkedAt: now()->subHours(23));
    procurementForPlatformHealthCheck(platform: $platform, broken: true);

    runCardPlatformHealthCheck(platform: $platform);

    Event::assertNotDispatched(PlatformHealthChanged::class);
});

test('неизменившийся плохой вердикт после окна напоминания порождает событие', function (): void {
    Event::fake();
    config(['process.diagnostics.repeat_alert_hours' => 24]);
    $platform = platformForPlatformHealthCheck();
    platformHealthCheck(platform: $platform, verdict: PlatformCheckVerdict::Broken, checkedAt: now()->subHours(25));
    procurementForPlatformHealthCheck(platform: $platform, broken: true);

    runCardPlatformHealthCheck(platform: $platform);

    Event::assertDispatched(PlatformHealthChanged::class, function (PlatformHealthChanged $event): bool {
        return $event->previousVerdict === PlatformCheckVerdict::Broken
            && $event->verdict === PlatformCheckVerdict::Broken;
    });
});

test('событие смены здоровья автоматически связано со слушателем', function (): void {
    Event::fake();

    Event::assertListening(PlatformHealthChanged::class, NotifyPlatformHealthChanged::class);
});
