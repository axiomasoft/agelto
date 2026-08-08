<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Health\PlatformHealthChecker;
use Illuminate\Console\Command;

final class HealthCheckCommand extends Command
{
    protected $signature = 'crawler:health-check
        {--platform= : Код площадки; по умолчанию — площадка эталона}
        {--kind= : Одна проверка: card_parse, listing_shape или estimate_fields}
        {--dry-run : Не записывать историю проверок}';

    protected $description = 'Запускает пассивные healthcheck-проверки площадки по штатным данным';

    public function __construct(private readonly PlatformHealthChecker $checker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $platformCode = (string) ($this->option('platform') ?: 'eis');
        $kind = $this->option('kind');

        try {
            $checkKind = $kind === null ? null : PlatformCheckKind::from((string) $kind);
            $platform = Platform::query()->where('code', $platformCode)->firstOrFail();
            $to = now();
            $from = $to->copy()->subHours((int) config('crawler.health.window_hours'));
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
