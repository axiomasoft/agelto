<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Infrastructure;

use App\Dto\Procurement\Process\Projection\ProcessEventEnvelope;
use App\Enums\Telemetry\LogChannel;
use App\Services\Procurement\Process\Contracts\TransitionSink;
use App\Support\Telemetry\TelemetrySanitizer;

/** Безопасный structured log accepted transition; envelope уже allowlisted, sanitizer — вторая защита. */
final readonly class LogTransitionSink implements TransitionSink
{
    public function __construct(private TelemetrySanitizer $sanitizer) {}

    public function publish(ProcessEventEnvelope $envelope): void
    {
        LogChannel::Process->info($envelope->event->value, $this->sanitizer->context($envelope->toArray()));
    }
}
