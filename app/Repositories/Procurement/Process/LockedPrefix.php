<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\LockedSubject;

/**
 * Состояние, прочитанное под полным prefix control → handler → capacity → subject → work. Все
 * решения grant/claim принимаются по нему, а не по unlocked pre-read.
 */
final readonly class LockedPrefix
{
    /**
     * @param  array<string, LockedLane>  $lanes  keyed by lane key
     * @param  array<string, LockedSubject>  $subjects  keyed by SubjectKey::key()
     * @param  array<string, LedgerRow>  $members  lane id → reservation identity/state, sorted by lane id
     * @param  list<string>  $enabledScopeKeys
     * @param  list<string>  $healthyHandlerIds
     */
    public function __construct(
        public bool $controlsEnabled,
        public bool $handlerHealthy,
        public array $lanes,
        public array $subjects,
        public LedgerRow $work,
        public bool $intentActive,
        public ?LedgerRow $group,
        public array $members,
        public array $enabledScopeKeys = [],
        public array $healthyHandlerIds = [],
    ) {}

    public function lane(string $key): LockedLane
    {
        return $this->lanes[$key] ?? throw new \LogicException('Required lane is not locked: '.$key);
    }
}
