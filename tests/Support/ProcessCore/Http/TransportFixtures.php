<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use Closure;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;

final class TransportFixtures
{
    /** @param array<string, mixed> $overrides */
    public static function profile(array $overrides = []): RequestProfile
    {
        return RequestProfile::fromArray(array_replace(NetworkFixtures::profile()->toArray(), [
            'connect_seconds' => 1, 'low_speed_seconds' => 2, 'total_seconds' => 4,
            'max_entity_bytes' => 65_536, 'max_text_bytes' => 196_608, 'delays_ms' => [0, 0],
        ], $overrides));
    }

    public static function plan(SafeUrl $url, ?RequestProfile $profile = null, IdempotencyClass $idempotency = IdempotencyClass::ReadOnly): OutboundRequestPlan
    {
        return new OutboundRequestPlan(LedgerFixtures::uuid(), LedgerFixtures::uuid(), 'GET', $url, $profile ?? self::profile(), TrafficClass::Detail, $idempotency);
    }

    public static function context(int $seconds = 120, ?Closure $checkpoint = null, ?Closure $renew = null): OutboundExecutionContext
    {
        $until = new \DateTimeImmutable('+'.$seconds.' seconds');
        $handle = new OutboundOperationHandle(LedgerFixtures::uuid(), OutboundOperationKind::Diagnostic, 1, LedgerFixtures::uuid(), $until, $until);

        return new OutboundExecutionContext($handle, $renew ?? fn ($handle) => $handle, $checkpoint, $seconds, 1);
    }

    public static function gate(?string $proxy = null): FixtureAttemptGate
    {
        return new FixtureAttemptGate([new HttpRouteBinding(LedgerFixtures::uuid(), $proxy)]);
    }
}
