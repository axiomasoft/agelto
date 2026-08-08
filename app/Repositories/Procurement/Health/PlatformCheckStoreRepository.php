<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Health;

use App\Dto\Procurement\Health\CheckResult;
use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Support\Carbon;

/** Единственный писатель истории healthcheck `prc_platform_checks`. */
final readonly class PlatformCheckStoreRepository
{
    public function store(
        Platform $platform,
        CheckResult $result,
        ?string $baselineSha256,
        ?Carbon $windowFrom,
        ?Carbon $windowTo,
        Carbon $checkedAt,
    ): PlatformCheck {
        return PlatformCheck::query()->create([
            'platform_id' => $platform->id,
            'check_kind' => $result->kind,
            'verdict' => $result->verdict,
            'measured' => $result->measured,
            'baseline_sha256' => $baselineSha256,
            'window_from' => $windowFrom,
            'window_to' => $windowTo,
            'sample_size' => $result->sampleSize,
            'requests_used' => $result->requestsUsed,
            'message' => $result->message,
            'checked_at' => $checkedAt,
        ]);
    }
}
