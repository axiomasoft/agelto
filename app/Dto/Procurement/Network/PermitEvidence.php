<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Versioned JSON boundary; policies/epochs stay typed maps inside the permit implementation. */
final readonly class PermitEvidence
{
    /**
     * @param  array<string, int>  $policies
     * @param  array<string, int>  $epochs
     */
    public function __construct(public array $policies, public array $epochs) {}

    /** @return array<string, int> */
    public static function decode(string $json, int $schema): array
    {
        if ($schema !== 1) {
            throw new \LogicException('Unsupported permit evidence schema.');
        }
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || $data === []) {
            throw new \LogicException('Permit evidence must be a nonempty version map.');
        }
        $versions = [];
        foreach ($data as $key => $version) {
            if (! is_string($key) || ! is_int($version) || $version < 1) {
                throw new \LogicException('Invalid permit evidence version.');
            }
            $versions[$key] = $version;
        }
        ksort($versions, SORT_STRING);

        return $versions;
    }
}
