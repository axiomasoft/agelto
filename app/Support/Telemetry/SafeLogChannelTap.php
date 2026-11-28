<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\PsrLogMessageProcessor;

final readonly class SafeLogChannelTap
{
    public function __construct(private SafeJsonLogProcessor $safeProcessor) {}

    public function __invoke(mixed $logger): void
    {
        $monolog = $logger instanceof Logger ? $logger->getLogger() : $logger;
        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $existing = [];
        while ($monolog->getProcessors() !== []) {
            $existing[] = $monolog->popProcessor();
        }

        $safe = null;
        $psr = null;
        $others = [];
        foreach ($existing as $processor) {
            if ($processor instanceof SafeJsonLogProcessor) {
                $safe ??= $processor;
            } elseif ($processor instanceof PsrLogMessageProcessor) {
                $psr ??= $processor;
            } else {
                $others[] = $processor;
            }
        }

        $ordered = [
            ...$others,
            $safe ?? $this->safeProcessor,
            $psr ?? new PsrLogMessageProcessor,
        ];

        for ($i = count($ordered) - 1; $i >= 0; $i--) {
            $monolog->pushProcessor($ordered[$i]);
        }
    }
}
