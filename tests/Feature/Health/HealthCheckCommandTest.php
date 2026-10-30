<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

test('команда записывает по строке на каждую проверку', function (): void {
    Platform::factory()->create(['code' => 'eis']);

    $this->artisan('process:diagnose', ['platform' => 'eis'])->assertExitCode(0);

    expect(PlatformCheck::query()->count())->toBe(4)
        ->and(PlatformCheck::query()->get()->pluck('check_kind')->map->value->sort()->values()->all())->toBe([
            PlatformCheckKind::CardParse->value,
            PlatformCheckKind::EstimateFields->value,
            PlatformCheckKind::ListingFilter->value,
            PlatformCheckKind::ListingShape->value,
        ]);
});

test('dry-run команды не записывает историю', function (): void {
    Platform::factory()->create(['code' => 'eis']);

    $this->artisan('process:diagnose', ['platform' => 'eis', '--dry-run' => true])->assertExitCode(0);

    expect(PlatformCheck::query()->count())->toBe(0);
});

test('выключенный healthcheck не читает площадку, не пишет историю и не ходит в сеть', function (): void {
    config(['crawler.health.enabled' => false]);
    Http::fake();

    $this->artisan('process:diagnose', ['platform' => 'eis'])->assertExitCode(0);

    expect(PlatformCheck::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('выключенный healthcheck не регистрируется в расписании', function (): void {
    config(['crawler.health.enabled' => false]);
    $schedule = app(Schedule::class);
    $healthEventsBefore = collect($schedule->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'crawler:health-check'))
        ->count();

    require base_path('routes/console.php');

    $healthEventsAfter = collect($schedule->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'crawler:health-check'))
        ->count();

    expect($healthEventsAfter)->toBe($healthEventsBefore);
});
