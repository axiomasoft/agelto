<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\AcquisitionKind;
use App\Services\Procurement\Acquisition\AcquisitionTick;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as F;
use Tests\Support\ProcessCore\ListingFixtures;

beforeEach(fn () => ListingFixtures::bind());

test('активный fresh-профиль регистрируется по своему cron', function (): void {
    $profile = F::create();
    $profile->update(['schedule' => '*/15 * * * *']);
    app(AcquisitionTick::class)->tick();

    $schedule = DB::table('prc_acquisition_schedules')->where('profile_id', $profile->id)->sole();
    expect($schedule->cron)->toBe('*/15 * * * *')->and($schedule->timezone)->toBe('UTC')
        ->and($schedule->acquisition_kind)->toBe(AcquisitionKind::Fresh->value)
        ->and($schedule->profile_version)->toBe($profile->profile_version);
});

test('пустое расписание не запускает профиль по cron, как обещает форма краулера', function (): void {
    $profile = F::create();
    $profile->update(['schedule' => null]);
    app(AcquisitionTick::class)->tick();

    expect(DB::table('prc_acquisition_schedules')->where('profile_id', $profile->id)->exists())->toBeFalse()
        ->and(DB::table('prc_process_runs')->where('profile_id', $profile->id)->exists())->toBeFalse();
});

test('невалидный cron одного профиля пропускается с ошибкой в логе и не ломает планировщик', function (): void {
    Log::spy();
    $broken = F::create();
    $broken->update(['schedule' => 'каждые 5 минут']);
    $healthy = F::create();
    $healthy->update(['schedule' => '0 * * * *']);
    app(AcquisitionTick::class)->tick();

    expect(DB::table('prc_acquisition_schedules')->pluck('profile_id')->all())->toBe([$healthy->id]);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['profile_id'] === $broken->id,
    )->once();
});

test('недоступная БД не роняет загрузку console.php и оставляет задачи без таблиц', function (): void {
    Log::spy();
    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('connection refused password=super-secret-dsn'));
    $schedule = app(Schedule::class);
    $before = count($schedule->events());

    require base_path('routes/console.php');

    // Загрузка routes/console.php не выполняет SQL-запросов к схеме до вычисления фильтров планировщика.
    Log::shouldNotHaveReceived('warning');

    $events = collect(array_slice($schedule->events(), $before));
    $maintainEvent = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'partitions:maintain'));
    expect($maintainEvent)->toBeInstanceOf(Event::class)
        ->and($maintainEvent->withoutOverlapping)->toBeTrue();

    $runnableCommands = $events
        ->filter(fn (Event $event): bool => $event->filtersPass(app()))
        ->map(fn (Event $event): string => (string) $event->command);

    expect($runnableCommands->contains(fn (string $command): bool => str_contains($command, 'partitions:maintain')))->toBeTrue()
        ->and($runnableCommands->contains(fn (string $command): bool => str_contains($command, 'process:schedule-acquisition')))->toBeFalse()
        ->and($runnableCommands->contains(fn (string $command): bool => str_contains($command, 'process:retention')))->toBeFalse()
        ->and($runnableCommands->contains(fn (string $command): bool => str_contains($command, 'process:health-report')))->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ($context['error_code'] ?? null) === 'scheduler_schema_unavailable'
            && ($context['exception_class'] ?? null) === RuntimeException::class
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'super-secret-dsn'),
    )->once();
});

test('сбой чтения профилей после успешной проверки схемы не роняет console.php', function (): void {
    // Profile reads belong to the producer command now, never to console bootstrap.
    DB::listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'from "prc_crawlers"')) {
            throw new RuntimeException('profile query failed');
        }
    });
    $schedule = app(Schedule::class);
    $before = count($schedule->events());
    require base_path('routes/console.php');

    $commands = collect(array_slice($schedule->events(), $before))->map(fn (Event $event): string => (string) $event->command);
    expect($commands->filter(fn (string $command): bool => str_contains($command, 'process:schedule-acquisition')))->toHaveCount(1);
});
