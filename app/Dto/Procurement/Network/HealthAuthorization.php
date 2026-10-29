<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\RouteKind;

/** Immutable send-time attribution metadata, not current mutable route configuration. */
final readonly class HealthAuthorization
{
    public function __construct(public RouteKind $kind, public int $credentialVersion, public ?string $independenceGroup, public ?string $probeToken)
    {
        if ($credentialVersion < 1 || ($independenceGroup !== null && ($independenceGroup === '' || strlen($independenceGroup) > 200))) {
            throw new \InvalidArgumentException('Invalid health authorization.');
        }
        if ($probeToken !== null) {
            new ProcessUuid($probeToken);
        }
    }

    /** @return array{kind: string, credential_version: int, independence_group: ?string, probe_token: ?string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'credential_version' => $this->credentialVersion,
            'independence_group' => $this->independenceGroup, 'probe_token' => $this->probeToken];
    }

    public static function decode(string $json, int $schema): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if ($schema !== 1 || ! is_array($data) || count($data) !== 4 || ! is_string($data['kind'] ?? null)
            || ! is_int($data['credential_version'] ?? null) || ! array_key_exists('independence_group', $data)
            || ! array_key_exists('probe_token', $data) || ($data['independence_group'] !== null && ! is_string($data['independence_group']))
            || ($data['probe_token'] !== null && ! is_string($data['probe_token']))) {
            throw new \LogicException('Invalid health authorization schema.');
        }

        return new self(RouteKind::from($data['kind']), $data['credential_version'], $data['independence_group'], $data['probe_token']);
    }
}
