<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\UnsupportedCapability;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Procurement\Network\Contracts\DiagnosticCheck;
use ReflectionClass;

/** Pure declaration validation. No container resolution, source requests or catalog synchronization. */
final class PlatformBootValidator
{
    public function validate(PlatformDefinition $definition, string $key): void
    {
        $path = 'platforms.definitions.'.$key;
        if ($key !== $definition->code->value || $definition->sourceTargets->platformCode !== $key) {
            throw new InvalidPlatformDefinition($path.': platform code/key/source-target mismatch.');
        }
        foreach ($definition->endpoints->endpoints as $endpoint) {
            $target = $definition->sourceTargets->targets[$endpoint->targetKey] ?? null;
            if ($target === null || ! $target->origin->equals(CanonicalOrigin::fromUrl($endpoint->url))
                || ! isset($target->profiles[$endpoint->profile->code])
                || $target->profiles[$endpoint->profile->code]->toArray() !== $endpoint->profile->toArray()) {
                throw new InvalidPlatformDefinition($path.'.endpoints.'.$endpoint->code.': target/origin/profile mismatch.');
            }
        }
        // These interfaces become available with their owning adapters. Unsupported declarations
        // do not pretend that a later capability has been implemented or validated.
        $contracts = [
            'listing' => 'App\\Services\\Procurement\\Platform\\Contracts\\ListingSource',
            'detail' => 'App\\Services\\Procurement\\Platform\\Contracts\\DetailSource',
            'status' => 'App\\Services\\Procurement\\Platform\\Contracts\\StatusSource',
            'documents' => 'App\\Services\\Procurement\\Platform\\Contracts\\DocumentAccessResolver',
            'partitioning' => 'App\\Services\\Procurement\\Platform\\Contracts\\ListingPartitioner',
        ];
        foreach ($contracts as $field => $contract) {
            $capability = $definition->{$field};
            if ($capability instanceof UnsupportedCapability) {
                continue;
            }
            $this->implementation($capability->implementation, $contract, $path.'.'.$field);
            foreach ($capability->endpoints as $endpoint) {
                $definition->endpoints->get($endpoint);
            }
        }
        foreach ($definition->checks->implementations as $code => $implementation) {
            $this->implementation($implementation, DiagnosticCheck::class, $path.'.checks.'.$code);
        }
        if ($definition->extension !== null) {
            $extension = $definition->extension;
            $this->implementation($extension->persister, 'App\\Services\\Procurement\\Platform\\Contracts\\ExtensionPersister', $path.'.extension.persister');
            if (! class_exists($extension->dto) || ! (new ReflectionClass($extension->dto))->isReadOnly()) {
                throw new InvalidPlatformDefinition($path.'.extension.dto: expected readonly DTO.');
            }
            $writer = new ReflectionClass($extension->persister);
            // Fully qualified generic argument makes the pairing unambiguous without constructing
            // the writer or calling adapter code while validating declarations.
            $doc = $writer->getDocComment() ?: '';
            if (! preg_match('/@implements\s+[^\s<]*ExtensionPersister\s*<\s*([^>\s]+)\s*>/', $doc, $match)
                || ltrim($match[1], '\\') !== $extension->dto) {
                throw new InvalidPlatformDefinition($path.'.extension.persister: exact fully qualified DTO generic is required.');
            }
            if ($extension->parserVersion->value !== $definition->version->value) {
                throw new InvalidPlatformDefinition($path.'.extension.parserVersion: adapter version mismatch.');
            }
        }
        if ($definition->uiExtension !== null) {
            $this->implementation($definition->uiExtension, 'App\\Contracts\\Procurement\\Platform\\PlatformUiExtension', $path.'.uiExtension');
        }
    }

    /** @phpstan-assert class-string $class */
    public function implementation(string $class, string $contract, string $path): void
    {
        if (! interface_exists($contract) || ! class_exists($class) || ! is_a($class, $contract, true)
            || ! (new ReflectionClass($class))->isInstantiable()) {
            throw new InvalidPlatformDefinition($path.': advertised implementation must be an instantiable '.$contract.'.');
        }
    }
}
