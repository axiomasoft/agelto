<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Acquisition\AdapterDiagnosticRunner;
use App\Services\Procurement\Health\PlatformHealthChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Запускает healthcheck-проверки площадки по штатным данным')]
#[Signature('process:diagnose
        {platform : Код площадки}
        {--kind= : Одна проверка: card_parse, listing_shape, estimate_fields или listing_filter}
        {--dry-run : Не записывать историю пассивных проверок}
        {--adapter= : Полная проверка контракта listing или detail без ingestion}
        {--crawler-id= : Профиль для проверки listing}
        {--procurement-id= : Закупка для проверки detail}
        {--release-input : Создать новый audited Process intent для failed_item карточки}
        {--reset-version= : Ожидаемая state_version для явного снятия adapter hold}
        {--actor-id= : Оператор с правом управления состоянием источника}
        {--reason= : Причина ручного восстановления}')]
final class ProcessDiagnoseCommand extends Command
{
    public function __construct(
        private readonly PlatformHealthChecker $checker,
        private readonly ?AdapterDiagnosticRunner $adapterRunner = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('adapter') !== null) {
            try {
                $report = ($this->adapterRunner ?? app(AdapterDiagnosticRunner::class))->diagnose(
                    (string) $this->argument('platform'), $this->option('adapter'), (bool) $this->option('dry-run'),
                    $this->option('crawler-id'), $this->option('procurement-id'), (bool) $this->option('release-input'),
                    $this->option('reset-version'), $this->option('actor-id'), $this->option('reason'),
                );
            } catch (\Throwable $exception) {
                $this->error('Контракт адаптера не принят: '.$exception->getMessage());

                return self::FAILURE;
            }
            if ($report['reset_info'] !== null) {
                $this->info($report['reset_info']);
            }
            if ($report['level'] !== null && $report['message'] !== null) {
                $this->{$report['level']}($report['message']);
            }

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        try {
            $platformOption = $this->argument('platform');
            if ($platformOption === '') {
                throw new \InvalidArgumentException('Код площадки должен быть непустой строкой.');
            }
            $kind = $this->option('kind');
            $checkKind = is_string($kind) && $kind !== '' ? PlatformCheckKind::from($kind) : null;
            $platform = Platform::query()->where('code', $platformOption)->firstOrFail();
            $to = now();
            $windowHours = config('process.diagnostics.window_hours');
            if (! is_int($windowHours) || $windowHours <= 0) {
                throw new \UnexpectedValueException('process.diagnostics.window_hours must be a positive integer.');
            }
            $results = $this->checker->run($platform, $to->copy()->subHours($windowHours), $to, $checkKind, (bool) $this->option('dry-run'));
        } catch (\Throwable $exception) {
            $this->warn("Healthcheck не выполнен: {$exception->getMessage()}");

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $line = "{$result->kind->value}: {$result->verdict->value}; {$result->message}";
            $result->verdict->value === 'ok' ? $this->info($line) : $this->warn($line);
        }

        return self::SUCCESS;
    }
}
