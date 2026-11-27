<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health\Checks;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Health\HealthCheckResultFactory;
use App\Services\Procurement\Health\PassiveHealthCheck;
use Carbon\CarbonInterface;

final readonly class ListingShapeCheck implements PassiveHealthCheck
{
    public function __construct(private HealthCheckResultFactory $results) {}

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::ListingShape;
    }

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult
    {
        $code = $platform->getAttribute('code');
        if (! is_string($code)) {
            throw new \LogicException('Missing platform code.');
        }
        $row = LedgerRow::one("SELECT count(*) FILTER (WHERE verdict IN ('accepted','contract_failure'))::bigint AS sample,
            count(*) FILTER (WHERE verdict='contract_failure')::bigint AS bad,
            count(*) FILTER (WHERE verdict NOT IN ('accepted','contract_failure'))::bigint AS incomplete FROM (
                SELECT DISTINCT ON (input_hash) details->>'verdict' AS verdict
                FROM prc_observations WHERE kind='adapter_validation' AND stale=false
                AND details->'contract'->>'platform_code'=? AND details->'contract'->>'capability'='listing'
                AND occurred_at BETWEEN ? AND ? ORDER BY input_hash,occurred_at DESC,observation_id DESC
            ) observations", [$code, $from->toDateTimeString(), $to->toDateTimeString()])
            ?? throw new \LogicException('Missing diagnostic aggregate.');
        $sample = $row->int('sample');
        $bad = $row->int('bad');
        $share = $sample === 0 ? 0.0 : $bad / $sample;

        return $this->results->make($this->kind(), $baseline,
            ['sample' => $sample, 'contract_failures' => $bad, 'contract_failure_share' => $share,
                'incomplete_assessments' => $row->int('incomplete'), 'source' => 'adapter_validation'], $sample, [$share]);
    }
}
