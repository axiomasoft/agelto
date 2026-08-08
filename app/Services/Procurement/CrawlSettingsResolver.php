<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\StageSettings;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;

/** Resolves one effective HTTP stage configuration without knowing its caller. */
final readonly class CrawlSettingsResolver
{
    public function forStage(CrawlStage $stage, Platform $platform, ?Crawler $crawler = null): StageSettings
    {
        $config = (array) config("crawler.stages.{$stage->value}", []);
        $throttle = $platform->throttle;
        $platformStage = $throttle === null ? null : $throttle->forStage($stage);
        $options = $crawler?->options;
        $profileStage = $stage === CrawlStage::Listing && $options !== null ? $options->listingOverrides() : null;

        return new StageSettings(
            minPauseMs: ($profileStage === null ? null : $profileStage->minPauseMs) ?? ($platformStage === null ? null : $platformStage->minPauseMs) ?? $config['min_pause_ms'] ?? null,
            maxPauseMs: ($profileStage === null ? null : $profileStage->maxPauseMs) ?? ($platformStage === null ? null : $platformStage->maxPauseMs) ?? $config['max_pause_ms'] ?? null,
            retries: ($profileStage === null ? null : $profileStage->retries) ?? ($platformStage === null ? null : $platformStage->retries) ?? $config['retries'] ?? null,
            retryDelayMs: ($profileStage === null ? null : $profileStage->retryDelayMs) ?? ($platformStage === null ? null : $platformStage->retryDelayMs) ?? $config['retry_delay_ms'] ?? null,
            timeoutSeconds: ($platformStage === null ? null : $platformStage->timeoutSeconds) ?? $config['timeout_seconds'] ?? null,
        );
    }
}
