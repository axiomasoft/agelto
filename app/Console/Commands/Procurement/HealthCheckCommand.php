<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Health\PlatformHealthChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Запускает healthcheck-проверки площадки по штатным данным')]
#[Signature('crawler:health-check
        {--platform= : Код площадки; по умолчанию — площадка эталона}
        {--kind= : Одна проверка: card_parse, listing_shape, estimate_fields или listing_filter}
        {--dry-run : Не записывать историю проверок}')]
final class HealthCheckCommand extends Command
{
    public function __construct(private readonly PlatformHealthChecker $checker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('crawler.health.enabled')) {
            $this->info('Healthcheck отключён конфигурацией.');

            return self::SUCCESS;
        }

        $platformOption = $this->option('platform');
        $platformCode = is_string($platformOption) && $platformOption !== '' ? $platformOption : 'eis';
        $kind = $this->option('kind');

        try {
            $checkKind = is_string($kind) && $kind !== '' ? PlatformCheckKind::from($kind) : null;
            $platform = Platform::query()->where('code', $platformCode)->firstOrFail();
            $to = now();
            $windowHours = config('crawler.health.window_hours');

            if (! is_int($windowHours) || $windowHours <= 0) {
                throw new \UnexpectedValueException('crawler.health.window_hours must be a positive integer.');
            }

            $from = $to->copy()->subHours($windowHours);
            $results = $this->checker->run(
                platform: $platform,
                from: $from,
                to: $to,
                kind: $checkKind,
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (\Throwable $exception) {
            $this->warn("Healthcheck не выполнен: {$exception->getMessage()}");

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $line = "{$result->kind->value}: {$result->verdict->value}; {$result->message}";

            if ($result->verdict->value === 'ok') {
                $this->info($line);

                continue;
            }

            $this->warn($line);
        }

        return self::SUCCESS;
    }
}
