<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Actions\Procurement\Network\ResetAdapterHealth;
use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SupportedDetailCapability;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Platform\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Acquisition\AcquisitionDiagnostic;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Health\PlatformHealthChecker;
use App\Services\Procurement\Platform\Contracts\ListingFilterCodec;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Proxy\ProxyResolver;
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
        $platformOption = $this->argument('platform');
        $platformCode = $platformOption;
        $kind = $this->option('kind');

        try {
            $checkKind = is_string($kind) && $kind !== '' ? PlatformCheckKind::from($kind) : null;
            $platform = Platform::query()->where('code', $platformCode)->firstOrFail();
            $to = now();
            $windowHours = config('process.diagnostics.window_hours');

            if (! is_int($windowHours) || $windowHours <= 0) {
                throw new \UnexpectedValueException('process.diagnostics.window_hours must be a positive integer.');
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
        $definition = app(PlatformRegistry::class)->get(new PlatformCode($code));
        $supported = $capability === 'listing' ? $definition->listing : $definition->detail;
        if (! $supported instanceof SupportedListingCapability
            && ! $supported instanceof SupportedDetailCapability) {
            throw new \InvalidArgumentException('Capability is unsupported.');
        }
        $endpoint = $definition->endpoints->get($supported->endpoints[0]);
        $contract = $definition->sourceTargets->targets[$endpoint->targetKey]->adapterContract
            ?? throw new \LogicException('Missing adapter contract.');
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
        $routes = app(ProxyResolver::class);
        if ($capability === 'listing' && $this->option('procurement-id') === null) {
            $crawler = Crawler::query()->whereKey($this->positiveOption('crawler-id'))
                ->whereHas('registry', fn ($query) => $query->where('platform_id', $platform->id))->firstOrFail();
            $launch = app(AcquisitionProfiles::class)->preparer($crawler)
                ->prepare($crawler, $crawler->listing_kind, null, null);
            $partitioning = $definition->partitioning;
            if (! $partitioning instanceof SupportedListingPartitioningCapability) {
                throw new \InvalidArgumentException('Missing listing partitioner.');
            }
            $codec = app($partitioning->implementation);
            if (! $codec instanceof ListingFilterCodec) {
                throw new \LogicException('Missing listing filter codec.');
            }
            $window = $launch->plan->windows[0] ?? throw new \InvalidArgumentException('No bounded listing window to validate.');
            $request = new ListingRequest($launch->registry, $codec->restoreFilter($launch->filter), $window->cursor, [], $launch->kind);
            $settings = AcquisitionConfiguration::decode($launch->configuration);
            $route = $routes->profile($settings->routeProfileId);
            $hash = ContentHash::of($launch->filter->payloadHash->hex.$window->cursor->payloadHash->hex);
        } elseif ($capability === 'detail' && $this->option('crawler-id') === null) {
            $procurement = Procurement::query()->whereKey($this->positiveOption('procurement-id'))
                ->where('platform_id', $platform->id)->firstOrFail();
            $work = LedgerRow::one("SELECT w.id,w.transition_seq,w.input_payload::text AS input,w.input_schema_version,
                r.configuration_snapshot::text AS configuration,r.configuration_schema_version
                FROM prc_process_work w JOIN prc_process_runs r ON r.id=w.origin_run_id
                WHERE w.stage='detail' AND w.input_payload->>'procurement_id'=? ORDER BY w.created_at DESC,w.id DESC LIMIT 1", [(string) $procurement->id])
                ?? throw new \InvalidArgumentException('No immutable acquisition detail request exists for this procurement.');
            $input = app(ProcessPayloadCodec::class)->decodeInput(
                new VersionedPayload($work->string('input'), $work->int('input_schema_version')));
            if (! $input instanceof DetailInput) {
                throw new \LogicException('Invalid detail work input.');
            }
            if ($this->option('release-input')) {
                $actor = User::query()->findOrFail($this->positiveOption('actor-id'));
                $reason = $this->option('reason');
                if (! is_string($reason) || trim($reason) === '') {
                    throw new \InvalidArgumentException('--reason обязателен для повтора входа.');
                }
                $result = app(ProcessOperatorCommands::class)->retryFailedItem($actor,
                    new WorkId(new ProcessUuid($work->string('id'))),
                    $work->int('transition_seq'), ListingStoreRepository::id('diagnostic-retry:'.$work->string('id').':'.$work->int('transition_seq')), $reason);
                if (! in_array($result->status, [OperatorCommandStatus::Applied, OperatorCommandStatus::Duplicate], true)) {
                    throw new \InvalidArgumentException('Process rejected the item retry: '.$result->status->value);
                }
            }
            $request = app(DetailSnapshotRepository::class)->request($procurement->id, $input->intentId);
            $settings = AcquisitionConfiguration::decode(
                new VersionedPayload($work->string('configuration'), $work->int('configuration_schema_version')));
            $route = $routes->profile($settings->routeProfileId);
            $hash = (new WorkIdentity($input))->key();
        } else {
            throw new \InvalidArgumentException('Тип входа не соответствует выбранному контракту.');
        }
        $validated = app(AcquisitionDiagnostic::class)
            ->fetch($definition, $platform->id, $request, $route, $hash);
        if ($validated->receipt?->verdict !== AdapterVerdict::Accepted) {
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
