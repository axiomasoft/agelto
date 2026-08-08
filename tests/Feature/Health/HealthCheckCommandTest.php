<?php

declare(strict_types=1);

use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;

test('команда записывает по строке на каждую пассивную проверку', function (): void {
    Platform::factory()->create(['code' => 'eis']);

    $this->artisan('crawler:health-check')->assertExitCode(0);

    expect(PlatformCheck::query()->count())->toBe(3);
});

test('dry-run команды не записывает историю', function (): void {
    Platform::factory()->create(['code' => 'eis']);

    $this->artisan('crawler:health-check', ['--dry-run' => true])->assertExitCode(0);

    expect(PlatformCheck::query()->count())->toBe(0);
});
