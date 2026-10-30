<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Actions\Procurement\Network\ResetAdapterHealth;
use App\Actions\Procurement\Network\ResetDetailInput;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Services\Procurement\Contracts\DiagnosticSourceMethod;
use App\Services\Procurement\Health\PlatformHealthChecker;
use App\Services\Procurement\PlatformAdapterRegistry;
use App\Services\Procurement\SourceMethodFactory;
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
        {--release-input : Снять локальный запрет повтора указанной карточки с audit reason}
        {--reset-version= : Ожидаемая state_version для явного снятия adapter hold}
        {--actor-id= : Оператор с правом управления состоянием источника}
        {--reason= : Причина ручного восстановления}')]
final class ProcessDiagnoseCommand extends Command
{
    public function __construct(private readonly PlatformHealthChecker $checker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('adapter') !== null) {
            try {
                return $this->diagnoseAdapter();
            } catch (\Throwable $exception) {
                $this->error('Контракт адаптера не принят: '.$exception->getMessage());

                return self::FAILURE;
            }
        }
        if (! config('crawler.health.enabled')) {
            $this->info('Healthcheck отключён конфигурацией.');

            return self::SUCCESS;
        }

        $platformOption = $this->argument('platform');
        $platformCode = $platformOption;
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

            return self::FAILURE;
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

    private function diagnoseAdapter(): int
    {
        if ($this->option('dry-run')) {
            throw new \InvalidArgumentException('Полная проверка требует сохранения канонического факта; --dry-run доступен пассивным проверкам.');
        }
        $capability = $this->option('adapter');
        if (! in_array($capability, ['listing', 'detail'], true)) {
            throw new \InvalidArgumentException('--adapter: listing или detail.');
        }
        $platform = Platform::query()->where('code', $this->argument('platform'))->firstOrFail();
        $code = $platform->getAttribute('code');
        if (! is_string($code)) {
            throw new \LogicException('Platform code is unavailable.');
        }
        $adapters = app(PlatformAdapterRegistry::class);
        $contract = $adapters->adapterContract($code, $capability);
        $reset = $this->option('reset-version');
        if ($reset !== null) {
            $version = $this->positiveOption('reset-version');
            $actor = User::query()->findOrFail($this->positiveOption('actor-id'));
            $reason = $this->option('reason');
            if (! is_string($reason) || trim($reason) === '') {
                throw new \InvalidArgumentException('--reason обязателен для ручного восстановления.');
            }
            $result = app(ResetAdapterHealth::class)->execute($actor, $contract, $version, $reason);
            if (! in_array($result->status, [NetworkChangeStatus::Applied,
                NetworkChangeStatus::Unchanged], true)) {
                $this->error('Восстановление отклонено: '.$result->status->value);

                return self::FAILURE;
            }
            $this->info('Контракт передан на проверку: '.$result->status->value);
        }
        if ($this->option('crawler-id') === null && $this->option('procurement-id') === null) {
            if ($reset !== null) {
                return self::SUCCESS;
            }
            throw new \InvalidArgumentException('Выберите вход: --crawler-id для listing, --procurement-id для detail.');
        }
        $factory = app(SourceMethodFactory::class);
        if ($capability === 'listing' && $this->option('procurement-id') === null) {
            $crawler = Crawler::query()->whereKey($this->positiveOption('crawler-id'))
                ->whereHas('registry', fn ($query) => $query->where('platform_id', $platform->id))->firstOrFail();
            $source = $factory->makeWithFallback($crawler);
            if (! $source instanceof DiagnosticSourceMethod) {
                throw new \LogicException('Adapter does not support full diagnostic validation.');
            }
            $validated = $source->diagnoseListing($crawler, new ListingCursor(blockFrom: 1), microtime(true) + 300);
        } elseif ($capability === 'detail' && $this->option('crawler-id') === null) {
            $procurement = Procurement::query()->whereKey($this->positiveOption('procurement-id'))
                ->whereHas('registry', fn ($query) => $query->where('platform_id', $platform->id))->firstOrFail();
            if ($this->option('release-input')) {
                $actor = User::query()->findOrFail($this->positiveOption('actor-id'));
                $reason = $this->option('reason');
                if (! is_string($reason) || trim($reason) === '') {
                    throw new \InvalidArgumentException('--reason обязателен для снятия запрета повтора входа.');
                }
                app(ResetDetailInput::class)->execute($actor, $procurement, $reason);
            }
            $registry = $procurement->registry ?? throw new \LogicException('Diagnostic registry is missing.');
            $source = $factory->make($adapters->detailMethod($registry), $registry);
            if (! $source instanceof DiagnosticSourceMethod) {
                throw new \LogicException('Adapter does not support full diagnostic validation.');
            }
            $validated = $source->diagnoseDetails($procurement->registry_number, $registry, microtime(true) + 300);
        } else {
            throw new \InvalidArgumentException('Тип входа не соответствует выбранному контракту.');
        }
        if ($validated->validation?->verdict !== AdapterVerdict::Accepted) {
            $this->warn('Получен частичный результат; полный контракт не подтверждён.');

            return self::FAILURE;
        }
        $this->info('Полный контракт принят; данные и курсоры acquisition не изменены.');

        return self::SUCCESS;
    }

    private function positiveOption(string $name): int
    {
        $value = $this->option($name);
        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('--'.$name.' должен быть положительным целым числом.');
        }

        return (int) $value;
    }
}
