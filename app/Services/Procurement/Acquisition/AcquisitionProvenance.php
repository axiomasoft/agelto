<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Repositories\Procurement\Process\LedgerRow;

final class AcquisitionProvenance
{
    /** Retry work keeps the acquisition origin; all observing runs remain linked through run_work. */
    public static function runFor(WorkInput $input): ?ProcessUuid
    {
        $id = LedgerRow::one("SELECT origin_run_id FROM prc_process_work WHERE work_key=decode(?,'hex')",
            [(new WorkIdentity($input))->key()->hex])?->nullableString('origin_run_id');
        if ($id === null && ($input instanceof DetailInput || $input instanceof StatusRefreshInput)) {
            $stage = $input instanceof DetailInput ? 'detail' : 'status_refresh';
            $id = LedgerRow::one(
                'SELECT origin_run_id FROM prc_process_work WHERE procurement_id=? AND stage=? AND origin_run_id IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 1',
                [$input->procurementId, $stage],
            )?->nullableString('origin_run_id');
        }

        return $id === null ? null : new ProcessUuid($id);
    }
}
