<?php

declare(strict_types=1);

use App\Dto\Procurement\Health\CheckResult;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Health\PlatformCheckStoreRepository;

test('репозиторий пишет строку истории со всеми полями', function (): void {
    $platform = Platform::factory()->create();
    $checkedAt = now();

    $check = app(PlatformCheckStoreRepository::class)->store(
        platform: $platform,
        result: new CheckResult(
            kind: PlatformCheckKind::CardParse,
            verdict: PlatformCheckVerdict::Degraded,
            measured: ['anchors_missing_critical' => 1],
            sampleSize: 4,
            requestsUsed: 2,
            message: 'Пороги не калиброваны.',
        ),
        baselineSha256: str_repeat('a', 64),
        windowFrom: now()->subDay(),
        windowTo: $checkedAt,
        checkedAt: $checkedAt,
    );

    $this->assertDatabaseHas('prc_platform_checks', [
        'id' => $check->id,
        'platform_id' => $platform->id,
        'check_kind' => PlatformCheckKind::CardParse->value,
        'verdict' => PlatformCheckVerdict::Degraded->value,
        'sample_size' => 4,
        'requests_used' => 2,
        'baseline_sha256' => str_repeat('a', 64),
    ]);
});
