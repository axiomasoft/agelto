<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\LeaseLost;

/** Pure adapter boundary. Publication and budget accounting remain owned by WorkFinalizer. */
final readonly class FetchOutcomeBridge
{
    public function failure(FetchDeferred|FetchBlocked|FetchItemRejected $result): StageDeferred|StageBlocked|StageFailedItem
    {
        if ($result instanceof FetchDeferred) {
            $reason = match ($result->reason) {
                NetworkDeferralReason::NoRoute => DeferralReason::NoRoute,
                NetworkDeferralReason::RateLimit, NetworkDeferralReason::StatusRefreshBudget => DeferralReason::RateLimit,
                NetworkDeferralReason::CapacityWait => DeferralReason::CapacityWait,
                NetworkDeferralReason::SliceExhausted => DeferralReason::SliceExhausted,
                NetworkDeferralReason::TargetCooldown, NetworkDeferralReason::AttemptsExhausted,
                NetworkDeferralReason::DependencyUnavailable => DeferralReason::TargetCooldown,
            };

            return new StageDeferred($reason, $result->retryAt);
        }
        if ($result instanceof FetchBlocked) {
            return new StageBlocked(match ($result->reason) {
                NetworkBlockedReason::Paused => BlockedReason::Paused,
                NetworkBlockedReason::AdapterHeld => BlockedReason::HandlerHold,
                NetworkBlockedReason::TargetRetired, NetworkBlockedReason::CredentialHold,
                NetworkBlockedReason::ConfigurationHold, NetworkBlockedReason::PolicyReview,
                NetworkBlockedReason::UrlPolicyDenied => BlockedReason::PolicyReview,
                NetworkBlockedReason::OwnerLost => throw new LeaseLost('Adapter execution ownership lost.'),
            });
        }
        // A schema/layout defect is not evidence that this particular procurement is invalid.
        if ($result->assessment->failureScope !== FailureScope::Item) {
            return new StageBlocked(BlockedReason::HandlerHold);
        }
        $evidence = $result->assessment->artifact;
        if (! $evidence instanceof ArtifactRef) {
            // A digest alone is not a persisted evidence artifact. The handler must store it first.
            throw new \InvalidArgumentException('Item rejection requires a persisted assessment artifact.');
        }
        if (! hash_equals($result->assessment->bodyHash->hex, $evidence->sha256->hex)) {
            throw new \InvalidArgumentException('Item rejection artifact does not match assessed bytes.');
        }

        return new StageFailedItem($result->reason, $evidence);
    }

    /** Caller supplies its durable progress checkpoint; this method never manufactures progress. */
    public function partial(ListingFetched|DetailFetched $result, StageCheckpoint $checkpoint): StagePartial
    {
        $missing = [];
        if ($result instanceof ListingFetched) {
            if ($checkpoint->stage() !== StageCode::Listing) {
                throw new \InvalidArgumentException('Listing checkpoint required.');
            }
            if (! $result->page->assessment->usable()) {
                $missing[] = 'listing';
            }
        } else {
            if ($checkpoint->stage() !== StageCode::Detail) {
                throw new \InvalidArgumentException('Detail checkpoint required.');
            }
            foreach ($result->snapshot->parts as $part) {
                if ($part->required && ! in_array($part->completeness, [Completeness::Complete, Completeness::CompleteEmpty], true)) {
                    $missing[] = $part->name;
                }
            }
        }
        if ($missing === []) {
            throw new \InvalidArgumentException('Complete result requires validated stage publication.');
        }

        return new StagePartial($checkpoint, new MissingParts($missing));
    }
}
