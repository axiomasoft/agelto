<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Platform\EndpointCatalog;
use App\Dto\Procurement\Platform\EndpointDefinition;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;

/** Config boundary only. Origin rate/retry execution and TLS remain owned by Network. */
final readonly class EisPolicy
{
    public int $blockSize;

    public DateTimeZone $sourceTimezone;

    public EndpointCatalog $endpoints;

    public EisListingPolicy $listing;

    public string $documentAccessResolver;

    public string $referenceEndpoint;

    public string $referenceRoot;

    public int $referenceRootLevel;

    public int $referenceMaxRequests;

    /** @var list<string> */
    public array $documentHosts;

    public function __construct(Repository $config)
    {
        $root = $config->get('platforms.eis');
        $this->keys($root, ['block_size', 'source_timezone', 'endpoints', 'listing_bounds', 'document_access_resolver', 'okpd2', 'documents_allowed_hosts'], 'platforms.eis');
        $reference = $root['okpd2'];
        $this->keys($reference, ['children_endpoint', 'root_external_id', 'root_level', 'max_requests'], 'platforms.eis.okpd2');
        foreach (['children_endpoint', 'root_external_id'] as $key) {
            if (! is_string($reference[$key]) || $reference[$key] === '') {
                throw new InvalidPlatformDefinition('platforms.eis.okpd2.'.$key.': expected nonempty string.');
            }
        }
        foreach (['root_level', 'max_requests'] as $key) {
            if (! is_int($reference[$key]) || $reference[$key] < 1) {
                throw new InvalidPlatformDefinition('platforms.eis.okpd2.'.$key.': expected positive integer.');
            }
        }
        $this->referenceEndpoint = $reference['children_endpoint'];
        $this->referenceRoot = $reference['root_external_id'];
        $this->referenceRootLevel = $reference['root_level'];
        $this->referenceMaxRequests = $reference['max_requests'];
        $hosts = $root['documents_allowed_hosts'];
        if (! is_array($hosts) || ! array_is_list($hosts) || $hosts === []) {
            throw new InvalidPlatformDefinition('platforms.eis.documents_allowed_hosts: expected nonempty list.');
        }
        foreach ($hosts as $host) {
            if (! is_string($host) || $host === '') {
                throw new InvalidPlatformDefinition('platforms.eis.documents_allowed_hosts: expected nonempty host string.');
            }
        }
        $this->documentHosts = $hosts;
        $resolver = $root['document_access_resolver'];
        if (! is_string($resolver) || $resolver === '') {
            throw new InvalidPlatformDefinition('platforms.eis.document_access_resolver: implementation class required.');
        }
        $this->documentAccessResolver = $resolver;
        $bounds = $root['listing_bounds'];
        $this->keys($bounds, ['max_blocks', 'min_interval_cents', 'max_depth', 'max_partitions', 'parser_seconds'], 'platforms.eis.listing_bounds');
        foreach ($bounds as $key => $value) {
            if (! is_int($value)) {
                throw new InvalidPlatformDefinition('platforms.eis.listing_bounds.'.$key.': expected integer.');
            }
        }
        try {
            $this->listing = new EisListingPolicy($bounds['max_blocks'], $bounds['min_interval_cents'],
                $bounds['max_depth'], $bounds['max_partitions'], $bounds['parser_seconds']);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidPlatformDefinition('platforms.eis.listing_bounds: invalid bounds.', previous: $exception);
        }
        $size = $root['block_size'];
        if (! is_int($size) || $size < 1 || $size > 1000) {
            throw new InvalidPlatformDefinition('platforms.eis.block_size: expected integer 1..1000.');
        }
        $this->blockSize = $size;
        $timezone = $root['source_timezone'];
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidPlatformDefinition('platforms.eis.source_timezone: invalid timezone.');
        }
        $this->sourceTimezone = new DateTimeZone($timezone);
        $endpoints = $root['endpoints'];
        $this->keys($endpoints, ['listing', 'card', 'document', 'diagnostic'], 'platforms.eis.endpoints');
        $catalog = [];
        foreach ($endpoints as $code => $data) {
            $path = 'platforms.eis.endpoints.'.$code;
            $this->keys($data, ['target', 'url', 'profile'], $path);
            if (! is_string($data['target']) || ! is_string($data['url']) || ! is_array($data['profile'])) {
                throw new InvalidPlatformDefinition($path.': invalid target, URL or profile.');
            }
            try {
                $profile = RequestProfile::fromArray($data['profile']);
                $catalog[] = new EndpointDefinition($code, $data['target'], $data['url'], $profile);
            } catch (\InvalidArgumentException $exception) {
                throw new InvalidPlatformDefinition($path.'.profile: '.$exception->getMessage(), previous: $exception);
            }
        }
        $this->endpoints = new EndpointCatalog($catalog);
    }

    /**
     * @param  list<string>  $keys
     *
     * @phpstan-assert array<string, mixed> $value
     */
    private function keys(mixed $value, array $keys, string $path): void
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidPlatformDefinition($path.': expected exactly '.implode(', ', $keys).'.');
        }
    }
}
