<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cron-выражения fresh-задач конкретного профиля после повторной загрузки `routes/console.php`.
 *
 * @return list<string>
 */
function freshScheduleFor(Crawler $crawler): array
{
    // `require` исполняет console.php в области ЭТОЙ функции: его цикл перезаписал бы `$crawler`.
    $crawlerId = $crawler->id;
    $schedule = app(Schedule::class);
    $before = count($schedule->events());

    require base_path('routes/console.php');

    return collect(array_slice($schedule->events(), $before))
        ->filter(fn (Event $event): bool => preg_match("/crawler:run-fresh'? '?{$crawlerId}'?$/", (string) $event->command) === 1)
        ->map(fn (Event $event): string => $event->expression)
        ->values()
        ->all();
}

test('активный fresh-профиль регистрируется по своему cron', function (): void {
    $crawler = Crawler::factory()->create(['is_active' => true, 'schedule' => '*/15 * * * *']);

    expect(freshScheduleFor($crawler))->toBe(['*/15 * * * *']);
});

test('пустое расписание не запускает профиль по cron, как обещает форма краулера', function (): void {
    $crawler = Crawler::factory()->create(['is_active' => true, 'schedule' => null]);

    expect(freshScheduleFor($crawler))->toBe([]);
});

test('невалидный cron одного профиля пропускается с ошибкой в логе и не ломает планировщик', function (): void {
    Log::spy();
    $broken = Crawler::factory()->create(['is_active' => true, 'schedule' => 'каждые 5 минут']);
    $healthy = Crawler::factory()->create(['is_active' => true, 'schedule' => '0 * * * *']);

    expect(freshScheduleFor($broken))->toBe([])
        ->and(freshScheduleFor($healthy))->toBe(['0 * * * *']);
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $context['crawler_id'] === $broken->id,
    )->atLeast()->once();
});

test('недоступная БД не роняет загрузку console.php и оставляет задачи без таблиц', function (): void {
    Log::spy();
    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('connection refused'));
    $schedule = app(Schedule::class);
    $before = count($schedule->events());

    require base_path('routes/console.php');

    $commands = collect(array_slice($schedule->events(), $before))->map(fn (Event $event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'partitions:maintain')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'crawler:')))->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});
