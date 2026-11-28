<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\PlatformThrottleSettings;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Validation\ValidationException;

test('площадка сохраняет типизированные настройки обхода', function (): void {
    $platform = Platform::factory()->create([
        'throttle' => [
            'requestsPerMinuteBase' => 20,
            'stages' => ['detail' => ['timeoutSeconds' => 45, 'dispatchBatchLimit' => 25]],
        ],
    ]);

    expect($platform->throttle)->toBeInstanceOf(PlatformThrottleSettings::class)
        ->and($platform->throttle?->requestsPerMinuteBase)->toBe(20)
        ->and($platform->throttle?->forStage(CrawlStage::Detail)?->timeoutSeconds)->toBe(45)
        ->and($platform->throttle?->forStage(CrawlStage::Detail)?->dispatchBatchLimit)->toBe(25);
});

test('неизвестный ключ настроек площадки отклоняется при сохранении', function (): void {
    expect(fn () => Platform::factory()->create(['throttle' => ['retryTimeout' => 10]]))
        ->toThrow(ValidationException::class);
});
