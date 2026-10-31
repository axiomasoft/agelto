<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Models\Procurement\Procurement;
use App\Services\Procurement\Process\ProcessTransaction;

/** Re-read current source_stage under the SAME subject lock; never bulk UPDATE status. */
final readonly class StatusRemapper
{
    public function __construct(private ProcessTransaction $transactions, private IdentityResolver $identities,
        private SnapshotFinalizer $snapshots, private HeadSnapshot $heads, private UnmappedStatusReporter $unknown) {}

    public function remap(int $procurementId, int $expectedHead): bool
    {
        $p = Procurement::query()->findOrFail($procurementId);
        $identity = $this->identities->forRegistry($p->registry_id, $p->source_identity_value);

        return $this->transactions->run(function () use ($identity, $expectedHead): bool {
            $locked = $this->snapshots->lockIdentities([$identity])[0];
            $p = $locked->procurement;
            if ($p === null || $p->head_generation !== $expectedHead) {
                return false;
            }
            $published = $this->snapshots->persistLocked($this->heads->make($p, $identity->source), $locked);
            $this->unknown->record($published);

            return true;
        });
    }
}
