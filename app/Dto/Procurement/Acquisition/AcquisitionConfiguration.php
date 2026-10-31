<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Services\Procurement\Process\PayloadReader;

/** Pinned execution settings; resolved defaults are distinct from command identity. */
final readonly class AcquisitionConfiguration
{
    public function __construct(
        public int $profileVersion,
        public ProcessUuid $routeProfileId,
        public int $routePolicyVersion,
        public AcquisitionKind $kind,
        public \DateTimeImmutable $initialFrom,
        public \DateTimeImmutable $through,
        public ?\DateTimeImmutable $requestedFrom,
        public ?\DateTimeImmutable $requestedThrough,
    ) {
        if (min($profileVersion, $routePolicyVersion) < 1 || $initialFrom > $through
            || ($requestedFrom !== null && $requestedThrough !== null && $requestedFrom > $requestedThrough)) {
            throw new \InvalidArgumentException('Invalid acquisition configuration bounds.');
        }
    }

    public static function decode(VersionedPayload $payload): self
    {
        if (! in_array($payload->schemaVersion, [1, 2], true) || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported acquisition configuration.');
        }
        $reader = PayloadReader::json($payload->json);
        $keys = ['profile_version', 'route_profile_id', 'route_policy_version', 'kind', 'initial_from', 'through'];
        $reader->keys($payload->schemaVersion === 2 ? [...$keys, 'requested_from', 'requested_through'] : $keys);

        return new self($reader->integer('profile_version'), new ProcessUuid($reader->string('route_profile_id')),
            $reader->integer('route_policy_version'), AcquisitionKind::from($reader->string('kind')),
            self::date($reader->string('initial_from')), self::date($reader->string('through')),
            $payload->schemaVersion === 2 && ! $reader->isNull('requested_from') ? self::date($reader->string('requested_from')) : null,
            $payload->schemaVersion === 2 && ! $reader->isNull('requested_through') ? self::date($reader->string('requested_through')) : null);
    }

    public function requestFingerprint(): ContentHash
    {
        return ContentHash::of(json_encode([$this->profileVersion, $this->routeProfileId->value,
            $this->routePolicyVersion, $this->kind->value, $this->requestedFrom?->format(DATE_ATOM),
            $this->requestedThrough?->format(DATE_ATOM)], JSON_THROW_ON_ERROR));
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
