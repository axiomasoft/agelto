<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\Provider\ReceiptRef;
use App\Enums\Procurement\Process\Outcomes\ProviderOperationPurpose;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;

final class ContractFixtures
{
    public static function id(int $suffix = 1): ProcessUuid
    {
        return new ProcessUuid('01900000-0000-7000-8000-'.str_pad((string) $suffix, 12, '0', STR_PAD_LEFT));
    }

    public static function hash(): ContentHash
    {
        return new ContentHash(str_repeat('a', 64));
    }

    public static function artifact(): ArtifactRef
    {
        return new ArtifactRef('fixtures', 'canonical/result.json', self::hash());
    }

    public static function receipt(): ReceiptRef
    {
        return new ReceiptRef(self::id(3), 'fixtures', 'llm/receipts/physical/response', self::hash(), 1);
    }

    public static function recovery(ProviderRecoveryMode $mode = ProviderRecoveryMode::ResumeFromReceipt, ProviderOperationPurpose $purpose = ProviderOperationPurpose::Generation, bool $receipt = true): ProviderRecoveryPlan
    {
        return new ProviderRecoveryPlan($mode, self::id(), self::id(2), $purpose, $receipt ? self::receipt() : null, self::hash(), new ContentHash(str_repeat('b', 64)), 4);
    }

    public static function codec(): ProcessPayloadCodec
    {
        return new ProcessPayloadCodec([new FixtureStagePayloadCodec]);
    }
}
