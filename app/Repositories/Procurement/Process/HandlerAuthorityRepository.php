<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Process\HandlerHold;
use App\Services\Procurement\Process\SchemaSupportedStages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registration handler-version authority `prc_process_handler_holds` (execution#s11). Stage
 * registration материализует healthy row unique(stage, handler_version) до первого admission:
 * отсутствие row не означает enabled. Повторная регистрация не снимает held и не стирает
 * incident; новая версия получает собственную row. Hold/validate mutations — detector/ValidateHandler.
 */
final readonly class HandlerAuthorityRepository
{
    /** Unlocked pre-read eligibility версии; null — row не материализована, это не enabled. */
    public function eligibility(StageCode $stage, string $handlerVersion): ?HandlerEligibility
    {
        $row = LedgerRow::one('SELECT state FROM prc_process_handler_holds WHERE stage = ? AND handler_version = ?', [$stage->value, $handlerVersion]);

        return $row === null ? null : HandlerEligibility::from($row->string('state'));
    }

    public function register(StageCode $stage, string $handlerVersion): HandlerAuthoritySnapshot
    {
        CallerTransaction::require('Handler authority registration');
        SchemaSupportedStages::ensureSupported($stage);
        if ($handlerVersion === '' || str_contains($handlerVersion, "\0")) {
            throw new \InvalidArgumentException('Handler version must be nonempty text.');
        }
        DB::insert(
            "INSERT INTO prc_process_handler_holds (id, stage, handler_version, state) VALUES (?, ?, ?, 'healthy')
             ON CONFLICT (stage, handler_version) DO NOTHING",
            [Str::lower((string) Str::uuid()), $stage->value, $handlerVersion],
        );
        $row = HandlerHold::query()->where('stage', $stage->value)->where('handler_version', $handlerVersion)->sole();

        return new HandlerAuthoritySnapshot(new ProcessUuid($row->id), $stage, $handlerVersion, $row->state, $row->version);
    }
}
