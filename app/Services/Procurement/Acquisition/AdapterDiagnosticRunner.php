<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Actions\Procurement\Network\ResetAdapterHealth;
use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\SupportedDetailCapability;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Platform\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
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
use App\Services\Procurement\Platform\Contracts\ListingFilterCodec;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;

final readonly class AdapterDiagnosticRunner
{
    public function __construct(
        private PlatformRegistry $platforms,
        private ResetAdapterHealth $resetHealth,
        private AcquisitionProfiles $profiles,
        private ProcessPayloadCodec $payloads,
        private ProcessOperatorCommands $operators,
        private DetailSnapshotRepository $details,
        private ProxyResolver $routes,
        private AcquisitionDiagnostic $diagnostic,
    ) {}

    /**
     * @return array{ok: bool, reset_info: ?string, level: 'info'|'warn'|'error'|null, message: ?string}
     */
    public function diagnose(
        string $platformArg,
        mixed $capability,
        bool $dryRun,
        mixed $crawlerId,
        mixed $procurementId,
        bool $releaseInput,
        mixed $resetVersion,
        mixed $actorId,
        mixed $reason,
    ): array {
        if ($dryRun) {
            throw new \InvalidArgumentException('Полная проверка требует сохранения канонического факта; --dry-run доступен пассивным проверкам.');
        }
        if (! in_array($capability, ['listing', 'detail'], true)) {
            throw new \InvalidArgumentException('--adapter: listing или detail.');
        }
        if ($platformArg === '') {
            throw new \InvalidArgumentException('Код площадки должен быть непустой строкой.');
        }
        $platform = Platform::query()->where('code', $platformArg)->firstOrFail();
        $code = $platform->getAttribute('code');
        if (! is_string($code)) {
            throw new \LogicException('Platform code is unavailable.');
        }
        $definition = $this->platforms->get(new PlatformCode($code));
        $supported = $capability === 'listing' ? $definition->listing : $definition->detail;
        if (! $supported instanceof SupportedListingCapability && ! $supported instanceof SupportedDetailCapability) {
            throw new \InvalidArgumentException('Capability is unsupported.');
        }
        $endpoint = $definition->endpoints->get($supported->endpoints[0]);
        $contract = $definition->sourceTargets->targets[$endpoint->targetKey]->adapterContract
            ?? throw new \LogicException('Missing adapter contract.');

        $resetInfo = null;
        if ($resetVersion !== null) {
            $version = self::positiveOption('reset-version', $resetVersion);
            $actor = User::query()->findOrFail(self::positiveOption('actor-id', $actorId));
            if (! is_string($reason) || trim($reason) === '') {
                throw new \InvalidArgumentException('--reason обязателен для ручного восстановления.');
            }
            $result = $this->resetHealth->execute($actor, $contract, $version, $reason);
            if (! in_array($result->status, [NetworkChangeStatus::Applied, NetworkChangeStatus::Unchanged], true)) {
                return [
                    'ok' => false,
                    'reset_info' => null,
                    'level' => 'error',
                    'message' => 'Восстановление отклонено: '.$result->status->value,
                ];
            }
            $resetInfo = 'Контракт передан на проверку: '.$result->status->value;
        }
        if ($crawlerId === null && $procurementId === null) {
            if ($resetVersion !== null) {
                return ['ok' => true, 'reset_info' => $resetInfo, 'level' => null, 'message' => null];
            }
            throw new \InvalidArgumentException('Выберите вход: --crawler-id для listing, --procurement-id для detail.');
        }

        if ($capability === 'listing' && $procurementId === null) {
            [$request, $route, $hash] = $this->prepareListing($definition, $platform->id, $crawlerId);
        } elseif ($capability === 'detail' && $crawlerId === null) {
            [$request, $route, $hash] = $this->prepareDetail($platform->id, $procurementId, $releaseInput, $actorId, $reason);
        } else {
            throw new \InvalidArgumentException('Тип входа не соответствует выбранному контракту.');
        }

        $validated = $this->diagnostic->fetch($definition, $platform->id, $request, $route, $hash);
        if ($validated->receipt?->verdict !== AdapterVerdict::Accepted) {
            return [
                'ok' => false,
                'reset_info' => $resetInfo,
                'level' => 'warn',
                'message' => 'Получен частичный результат; полный контракт не подтверждён.',
            ];
        }

        return [
            'ok' => true,
            'reset_info' => $resetInfo,
            'level' => 'info',
            'message' => 'Полный контракт принят; данные и курсоры acquisition не изменены.',
        ];
    }

    /**
     * @return array{0: ListingRequest, 1: RoutePolicy, 2: ContentHash}
     */
    private function prepareListing(PlatformDefinition $definition, int $platformId, mixed $crawlerId): array
    {
        $crawler = Crawler::query()->whereKey(self::positiveOption('crawler-id', $crawlerId))
            ->whereHas('registry', fn ($query) => $query->where('platform_id', $platformId))->firstOrFail();
        $launch = $this->profiles->preparer($crawler)->prepare($crawler, $crawler->listing_kind, null, null);
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
        $route = $this->routes->profile($settings->routeProfileId);
        $hash = ContentHash::of($launch->filter->payloadHash->hex.$window->cursor->payloadHash->hex);

        return [$request, $route, $hash];
    }

    /**
     * @return array{0: DetailRequest, 1: RoutePolicy, 2: ContentHash}
     */
    private function prepareDetail(int $platformId, mixed $procurementId, bool $releaseInput, mixed $actorId, mixed $reason): array
    {
        $procurement = Procurement::query()->whereKey(self::positiveOption('procurement-id', $procurementId))
            ->where('platform_id', $platformId)->firstOrFail();
        $work = LedgerRow::one(
            "SELECT w.id,w.transition_seq,w.input_payload::text AS input,w.input_schema_version,
                r.configuration_snapshot::text AS configuration,r.configuration_schema_version
             FROM prc_process_work w JOIN prc_process_runs r ON r.id=w.origin_run_id
             WHERE w.stage='detail' AND w.input_payload->>'procurement_id'=? ORDER BY w.created_at DESC,w.id DESC LIMIT 1",
            [(string) $procurement->id],
        ) ?? throw new \InvalidArgumentException('No immutable acquisition detail request exists for this procurement.');
        $input = $this->payloads->decodeInput(new VersionedPayload($work->string('input'), $work->int('input_schema_version')));
        if (! $input instanceof DetailInput) {
            throw new \LogicException('Invalid detail work input.');
        }
        if ($releaseInput) {
            $actor = User::query()->findOrFail(self::positiveOption('actor-id', $actorId));
            if (! is_string($reason) || trim($reason) === '') {
                throw new \InvalidArgumentException('--reason обязателен для повтора входа.');
            }
            $result = $this->operators->retryFailedItem(
                $actor,
                new WorkId(new ProcessUuid($work->string('id'))),
                $work->int('transition_seq'),
                ListingStoreRepository::id('diagnostic-retry:'.$work->string('id').':'.$work->int('transition_seq')),
                $reason,
            );
            if (! in_array($result->status, [OperatorCommandStatus::Applied, OperatorCommandStatus::Duplicate], true)) {
                throw new \InvalidArgumentException('Process rejected the item retry: '.$result->status->value);
            }
        }
        $request = $this->details->request($procurement->id, $input->intentId);
        $settings = AcquisitionConfiguration::decode(new VersionedPayload($work->string('configuration'), $work->int('configuration_schema_version')));
        $route = $this->routes->profile($settings->routeProfileId);
        $hash = (new WorkIdentity($input))->key();

        return [$request, $route, $hash];
    }

    private static function positiveOption(string $name, mixed $value): int
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('--'.$name.' должен быть положительным целым числом.');
        }

        return (int) $value;
    }
}
