<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\UnsupportedCapability;
use PHPUnit\Framework\Assert;

/** Offline only. New platforms register manifests; no platform names or formats belong here. */
final readonly class AdapterContractKit
{
    private const array REQUIRED = [
        'listing' => ['valid', 'empty', 'truncated', 'overflow', 'loop', 'identity_determinism'],
        'detail' => ['valid', 'required_sections', 'wrong_identity', 'status', 'redaction'],
        'partitioning' => ['split', 'bounds'],
        'status' => ['valid', 'unknown'],
        'documents' => ['valid', 'expired'],
    ];

    /** @param list<PlatformDefinition> $definitions
     * @param  array<string,ContractManifest>  $manifests
     */
    public function verifyRegistry(array $definitions, array $manifests): void
    {
        foreach ($definitions as $definition) {
            $manifest = $manifests[$definition->code->value] ?? throw new \LogicException('Missing capability manifest: '.$definition->code->value);
            Assert::assertEquals($definition, $manifest->definition, 'Fixture definition differs from registered definition.');
            $this->verify($manifest);
        }
    }

    /** @return list<string> Actual executed case IDs, not advertised coverage. */
    public function verify(ContractManifest $manifest): array
    {
        $keys = [];
        foreach ($manifest->cases as $case) {
            if (isset($keys[$case->key()])) {
                throw new \LogicException('Duplicate semantic check: '.$case->key());
            }
            if (! isset(self::REQUIRED[$case->capability]) || $manifest->definition->{$case->capability} instanceof UnsupportedCapability) {
                throw new \LogicException('Fixture advertises unsupported capability: '.$case->key());
            }
            if ($case->fixtures === []) {
                throw new \LogicException('Missing fixture: '.$case->key());
            }
            foreach ($case->fixtures as $path) {
                FixtureCorpus::read($path);
            }
            $keys[$case->key()] = true;
        }
        $required = $manifest->additionalRequired;
        foreach (self::REQUIRED as $capability => $scenarios) {
            if (! $manifest->definition->{$capability} instanceof UnsupportedCapability) {
                foreach ($scenarios as $scenario) {
                    $required[] = $capability.'.'.$scenario;
                }
            }
        }
        foreach ($required as $key) {
            if (! isset($keys[$key])) {
                throw new \LogicException('Missing semantic check: '.$key);
            }
        }
        foreach ($manifest->cases as $case) {
            $this->verifyCase($case);
        }

        return array_keys($keys);
    }

    public function verifyCase(ContractCase $case): void
    {
        Assert::assertEquals($case->expected, ($case->observe)(), 'Semantic contract mismatch: '.$case->key());
    }
}
