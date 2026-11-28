<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Acquisition\Control\ProcurementIdentity;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Support\Facades\DB;

final readonly class IdentityResolver
{
    public function __construct(private PlatformRegistry $platforms) {}

    public function forRegistry(int $registryId, string $value): ProcurementIdentity
    {
        $row = DB::table('prc_registries as r')->join('prc_platforms as p', 'p.id', '=', 'r.platform_id')
            ->where('r.id', $registryId)->first(['r.platform_id', 'r.code', 'p.code as platform_code']);
        if ($row === null || ! is_string($row->code) || ! is_string($row->platform_code) || ! is_int($row->platform_id)) {
            throw new \InvalidArgumentException('Unknown identity registry.');
        }
        $platform = new PlatformCode($row->platform_code);
        $registry = $this->platforms->get($platform)->registries->get($row->code);

        return new ProcurementIdentity((int) $row->platform_id, $registryId,
            new SourceIdentity($platform, $registry->primaryIdentityType, new SourceIdentifierValue($value)));
    }

    public function resolve(int $registryId, SourceIdentity $source): ProcurementIdentity
    {
        $identity = $this->forRegistry($registryId, $source->value->value);
        if ($identity->source->toArray() !== $source->toArray()) {
            throw new \InvalidArgumentException('Source identity does not match request registry.');
        }

        return $identity;
    }
}
