<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Tests\Support\ProcessCore\ContractFixtures;

final readonly class ContractRuntime
{
    public static function response(string $body, string $type): ResponseEnvelope
    {
        return new ResponseEnvelope(200, ['content-type' => [$type]], new DecodedTextBody($body, 'UTF-8'), strlen($body), strlen($body));
    }

    /** Pure fetch tests only; real ledger tests use the WorkRunner context. */
    public static function context(?\DateTimeImmutable $databaseTime = null): ExecutionContext
    {
        $databaseTime ??= new \DateTimeImmutable;
        $clock = \Mockery::mock(ProcessClock::class);
        $clock->shouldReceive('monotonicNs')->andReturn(1000000000);
        $lease = new ExecutionLease(new WorkId(ContractFixtures::id()), ContractFixtures::id(2), 1, ContractFixtures::id(3),
            $databaseTime->modify('+360 seconds'), 1, ContractFixtures::hash(), 0, null, StageCode::Listing, 'fixture/1');
        $clock->shouldReceive('dbNow')->andReturn(new DbInstant($databaseTime));

        return new ExecutionContext($lease, \Mockery::mock(LeaseKeeper::class), $clock, Duration::seconds(240),
            Duration::seconds(360), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
    }
}
