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
        $config = $this->defaultForStage(stage: $stage);
        $throttle = $platform->throttle;
        $platformStage = $throttle === null ? null : $throttle->forStage($stage);
        $options = $crawler?->options;
        $profileStage = $stage === CrawlStage::Listing && $options !== null ? $options->listingOverrides() : null;

        return new StageSettings(
            minPauseMs: ($profileStage === null ? null : $profileStage->minPauseMs) ?? ($platformStage === null ? null : $platformStage->minPauseMs) ?? $config->minPauseMs,
            maxPauseMs: ($profileStage === null ? null : $profileStage->maxPauseMs) ?? ($platformStage === null ? null : $platformStage->maxPauseMs) ?? $config->maxPauseMs,
            retries: ($profileStage === null ? null : $profileStage->retries) ?? ($platformStage === null ? null : $platformStage->retries) ?? $config->retries,
            retryDelayMs: ($profileStage === null ? null : $profileStage->retryDelayMs) ?? ($platformStage === null ? null : $platformStage->retryDelayMs) ?? $config->retryDelayMs,
            timeoutSeconds: ($platformStage === null ? null : $platformStage->timeoutSeconds) ?? $config->timeoutSeconds,
            dispatchBatchLimit: ($platformStage === null ? null : $platformStage->dispatchBatchLimit) ?? $config->dispatchBatchLimit,
        );
    }

    /** Resolves config defaults when a scheduler works across all platforms at once. */
    public function defaultForStage(CrawlStage $stage): StageSettings
    {
        $config = (array) config("crawler.stages.{$stage->value}", []);

        return new StageSettings(
            minPauseMs: $config['min_pause_ms'] ?? null,
            maxPauseMs: $config['max_pause_ms'] ?? null,
            retries: $config['retries'] ?? null,
            retryDelayMs: $config['retry_delay_ms'] ?? null,
            timeoutSeconds: $config['timeout_seconds'] ?? null,
            dispatchBatchLimit: $config['dispatch_batch_limit'] ?? null,
        );
    }
}
