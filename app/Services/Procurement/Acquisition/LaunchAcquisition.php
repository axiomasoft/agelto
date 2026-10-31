<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Versioned operator launch; the scheduler owns all ledger mutations and deduplication. */
final readonly class LaunchAcquisition
{
    public function __construct(private AcquisitionProfiles $profiles, private AcquisitionScheduler $scheduler) {}

    public function execute(User $actor, Crawler $profile, int $expectedVersion, ProcessUuid $command): ProcessUuid
    {
        if (! $actor->hasPermission(ProcessControlPermission::LaunchRun, panelId: 'admin')) {
            throw new AuthorizationException('Acquisition launch is not permitted.');
        }
        $profile->refresh();
        if ($profile->profile_version !== $expectedVersion) {
            throw new \InvalidArgumentException('Профиль изменился. Откройте подтверждение заново.');
        }

        return $this->scheduler->manual($this->profiles->prepare($profile, $profile->listing_kind), $command, $actor->id);
    }
}
