<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ProcessEventEnvelope;
use App\Services\Procurement\Process\Contracts\TransitionSink;
use App\Support\Telemetry\TelemetrySanitizer;
use Sentry\Breadcrumb;

/** Breadcrumb accepted transition; Sentry не источник истины и не подтверждение завершения. */
final readonly class SentryTransitionSink implements TransitionSink
{
    public function __construct(private TelemetrySanitizer $sanitizer) {}

    public function publish(ProcessEventEnvelope $envelope): void
    {
        \Sentry\addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'process.transition',
            $envelope->event->value,
            $this->sanitizer->stringContext($envelope->toArray()),
        ));
    }
}
