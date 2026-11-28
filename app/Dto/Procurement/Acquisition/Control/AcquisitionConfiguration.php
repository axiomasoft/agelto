<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Control;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;

/** Pinned execution settings; resolved defaults are distinct from command identity. */
final readonly class AcquisitionConfiguration
{
    /** @var array<string, array{route_profile_id: ProcessUuid, route_policy_version: int}> */
    public array $stageRouteProfiles;

    /**
     * @param  array<string, array{route_profile_id: ProcessUuid, route_policy_version: int}>  $stageRouteProfiles
     */
    public function __construct(
        public int $profileVersion,
        public ProcessUuid $routeProfileId,
        public int $routePolicyVersion,
        public AcquisitionKind $kind,
        public \DateTimeImmutable $initialFrom,
        public \DateTimeImmutable $through,
        public ?\DateTimeImmutable $requestedFrom,
        public ?\DateTimeImmutable $requestedThrough,
        array $stageRouteProfiles = [],
    ) {
        if (min($profileVersion, $routePolicyVersion) < 1 || $initialFrom > $through
            || ($requestedFrom !== null && $requestedThrough !== null && $requestedFrom > $requestedThrough)) {
            throw new \InvalidArgumentException('Invalid acquisition configuration bounds.');
        }
        $normalized = [];
        foreach ($stageRouteProfiles as $stage => $binding) {
            if (! in_array($stage, ['listing', 'detail', 'status_refresh'], true)
                || $binding['route_policy_version'] < 1) {
                throw new \InvalidArgumentException('Invalid stage route profile binding.');
            }
            $normalized[$stage] = [
                'route_profile_id' => $binding['route_profile_id'],
                'route_policy_version' => $binding['route_policy_version'],
            ];
        }
        ksort($normalized, SORT_STRING);
        $this->stageRouteProfiles = $normalized;
    }

    public function hasStageRouteProfile(StageCode $stage): bool
    {
        return isset($this->stageRouteProfiles[$stage->value]);
    }

    public function routeProfileIdForStage(StageCode $stage): ProcessUuid
    {
        return $this->stageRouteProfiles[$stage->value]['route_profile_id'] ?? $this->routeProfileId;
    }

    public function routePolicyVersionForStage(StageCode $stage): int
    {
        return $this->stageRouteProfiles[$stage->value]['route_policy_version'] ?? $this->routePolicyVersion;
    }

    public function encode(): VersionedPayload
    {
        $payload = [
            'profile_version' => $this->profileVersion,
            'route_profile_id' => $this->routeProfileId->value,
            'route_policy_version' => $this->routePolicyVersion,
            'kind' => $this->kind->value,
            'initial_from' => $this->initialFrom->format(DATE_ATOM),
            'through' => $this->through->format(DATE_ATOM),
            'requested_from' => $this->requestedFrom?->format(DATE_ATOM),
            'requested_through' => $this->requestedThrough?->format(DATE_ATOM),
        ];
        if ($this->stageRouteProfiles !== []) {
            $stages = [];
            foreach ($this->stageRouteProfiles as $stage => $binding) {
                $stages[$stage] = [
                    'route_profile_id' => $binding['route_profile_id']->value,
                    'route_policy_version' => $binding['route_policy_version'],
                ];
            }
            $payload['stage_route_profiles'] = $stages;
        }

        return new VersionedPayload(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->stageRouteProfiles === [] ? 2 : 3,
        );
    }

    public static function decode(VersionedPayload $payload): self
    {
        if (! in_array($payload->schemaVersion, [1, 2, 3], true) || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported acquisition configuration.');
        }
        $reader = PayloadReader::json($payload->json);
        $keys = ['profile_version', 'route_profile_id', 'route_policy_version', 'kind', 'initial_from', 'through'];
        if ($payload->schemaVersion >= 2) {
            $keys = [...$keys, 'requested_from', 'requested_through'];
        }
        if ($payload->schemaVersion === 3) {
            $keys = [...$keys, 'stage_route_profiles'];
        }
        $reader->keys($keys);
        $stageProfiles = [];
        if ($payload->schemaVersion === 3) {
            $rawData = json_decode($payload->json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($rawData) || ! is_array($rawData['stage_route_profiles'] ?? null)) {
                throw new \InvalidArgumentException('Invalid stage_route_profiles in acquisition configuration.');
            }
            foreach ($rawData['stage_route_profiles'] as $stage => $entry) {
                if (! is_string($stage) || ! is_array($entry)
                    || array_keys($entry) !== ['route_profile_id', 'route_policy_version']
                    || ! is_string($entry['route_profile_id']) || ! is_int($entry['route_policy_version'])) {
                    throw new \InvalidArgumentException('Invalid stage route profile entry.');
                }
                $stageProfiles[$stage] = [
                    'route_profile_id' => new ProcessUuid($entry['route_profile_id']),
                    'route_policy_version' => $entry['route_policy_version'],
                ];
            }
        }

        return new self($reader->integer('profile_version'), new ProcessUuid($reader->string('route_profile_id')),
            $reader->integer('route_policy_version'), AcquisitionKind::from($reader->string('kind')),
            self::date($reader->string('initial_from')), self::date($reader->string('through')),
            $payload->schemaVersion >= 2 && ! $reader->isNull('requested_from') ? self::date($reader->string('requested_from')) : null,
            $payload->schemaVersion >= 2 && ! $reader->isNull('requested_through') ? self::date($reader->string('requested_through')) : null,
            $stageProfiles);
    }

    public function requestFingerprint(): ContentHash
    {
        if ($this->stageRouteProfiles === []) {
            return ContentHash::of(json_encode([$this->profileVersion, $this->routeProfileId->value,
                $this->routePolicyVersion, $this->kind->value, $this->requestedFrom?->format(DATE_ATOM),
                $this->requestedThrough?->format(DATE_ATOM)], JSON_THROW_ON_ERROR));
        }
        $stages = [];
        foreach ($this->stageRouteProfiles as $stage => $binding) {
            $stages[$stage] = [$binding['route_profile_id']->value, $binding['route_policy_version']];
        }

        return ContentHash::of(json_encode([$this->profileVersion, $this->routeProfileId->value,
            $this->routePolicyVersion, $this->kind->value, $this->requestedFrom?->format(DATE_ATOM),
            $this->requestedThrough?->format(DATE_ATOM), $stages], JSON_THROW_ON_ERROR));
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if ($date === false || $date->format(DATE_ATOM) !== $value) {
            throw new \InvalidArgumentException('Acquisition dates must be valid RFC3339 timestamps.');
        }

        return $date;
    }
}
