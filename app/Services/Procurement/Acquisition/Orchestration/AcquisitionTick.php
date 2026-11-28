<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Orchestration;

use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Telemetry\LogChannel;
use App\Models\Procurement\Crawler\Crawler;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Acquisition\Listing\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\Listing\ListingRecovery;

/** The database schedule is the durable authority; the minute timer only wakes this producer. */
final readonly class AcquisitionTick
{
    public function __construct(private AcquisitionProfiles $profiles, private AcquisitionScheduler $scheduler,
        private RefreshRepository $refreshes, private ListingRecovery $recovery) {}

    public function tick(): void
    {
        Crawler::query()->where('is_active', true)->orderBy('id')->eachById(function (Crawler $profile): void {
            try {
                $cron = trim((string) $profile->schedule);
                if ($cron !== '') {
                    $this->scheduler->configure($profile->id, $profile->listing_kind, $cron, 'UTC', $profile->profile_version);
                }
                foreach (LedgerRow::all('SELECT acquisition_kind FROM prc_acquisition_schedules
                    WHERE profile_id=? AND profile_version=? AND next_due_at<=clock_timestamp() ORDER BY acquisition_kind', [$profile->id, $profile->profile_version]) as $row) {
                    $kind = AcquisitionKind::from($row->string('acquisition_kind'));
                    if ($kind === $profile->listing_kind && $cron === '') {
                        continue;
                    }
                    $this->scheduler->due($this->profiles->prepare($profile, $kind));
                }
                $this->recovery->due($profile);
            } catch (\InvalidArgumentException $failure) {
                // A bad profile is visible and cannot prevent other profiles from making progress.
                LogChannel::Procurement->warning('Acquisition profile requires correction.', ['profile_id' => $profile->id,
                    'profile_version' => $profile->profile_version, 'reason' => $failure->getMessage()]);
            }
        }, 100);
        $this->refreshes->due();
    }
}
