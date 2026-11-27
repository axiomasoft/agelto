<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\AdapterValidation;

use App\Dto\Procurement\Process\Primitives\ContentHash;

/** Explicit implementation/contract identity; deployments and observations never invent versions. */
final readonly class AdapterContractRef
{
    public function __construct(public string $platformCode, public string $capability,
        public string $adapterVersion, public string $contractVersion)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $platformCode) !== 1
            || preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $capability) !== 1) {
            throw new \InvalidArgumentException('Invalid adapter contract identity.');
        }
        foreach ([$adapterVersion, $contractVersion] as $version) {
            if (preg_match('~^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,95}$~D', $version) !== 1) {
                throw new \InvalidArgumentException('Invalid explicit adapter contract version.');
            }
        }
    }

    /** @return array{platform_code:string,capability:string,adapter_version:string,contract_version:string} */
    public function toArray(): array
    {
        return ['platform_code' => $this->platformCode, 'capability' => $this->capability,
            'adapter_version' => $this->adapterVersion, 'contract_version' => $this->contractVersion];
    }

    /** @param array<array-key,mixed> $data */
    public static function fromArray(array $data): self
    {
        $expected = ['platform_code', 'capability', 'adapter_version', 'contract_version'];
        if (count($data) !== count($expected) || array_diff(array_keys($data), $expected) !== []) {
            throw new \InvalidArgumentException('Invalid adapter contract binding.');
        }
        foreach ($expected as $key) {
            if (! is_string($data[$key])) {
                throw new \InvalidArgumentException('Adapter contract values must be strings.');
            }
        }

        return new self($data['platform_code'], $data['capability'], $data['adapter_version'], $data['contract_version']);
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    public function fingerprint(): ContentHash
    {
        return new ContentHash(hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR)));
    }
}
