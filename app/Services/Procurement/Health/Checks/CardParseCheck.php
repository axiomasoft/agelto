<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health\Checks;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Health\HealthCheckResultFactory;
use App\Services\Procurement\Health\PassiveHealthCheck;
use Carbon\CarbonInterface;

final readonly class CardParseCheck implements PassiveHealthCheck
{
    public function __construct(private HealthCheckResultFactory $results) {}

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::CardParse;
    }

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult
    {
        $code = $platform->getAttribute('code');
        if (! is_string($code)) {
            throw new \LogicException('Missing platform code.');
        }
        // One latest non-stale assessment per immutable input. A complete parser contract,
        // including declared optional sections, is assessed by the registered adapter.
        $row = LedgerRow::one("SELECT count(*) FILTER (WHERE verdict IN ('accepted','contract_failure'))::bigint AS sample,
            count(*) FILTER (WHERE verdict='contract_failure')::bigint AS bad FROM (
                SELECT DISTINCT ON (input_hash) details->>'verdict' AS verdict
                FROM prc_observations WHERE kind='adapter_validation' AND stale=false
                AND details->'contract'->>'platform_code'=? AND details->'contract'->>'capability'='detail'
                AND occurred_at BETWEEN ? AND ? ORDER BY input_hash,occurred_at DESC,observation_id DESC
            ) observations", [$code, $from->format('Y-m-d H:i:s.uP'), $to->format('Y-m-d H:i:s.uP')])
            ?? throw new \LogicException('Missing diagnostic aggregate.');
        $sample = $row->int('sample');
        $bad = $row->int('bad');
        $knownBrokenTabs = 0;
        $share = $sample === 0 ? 0 : $bad / $sample;

        return $this->results->make(
            kind: $this->kind(),
            baseline: $baseline,
            measured: ['sample' => $sample, 'bad' => $bad, 'share' => $share, 'known_broken_tabs_excluded' => $knownBrokenTabs],
            sampleSize: $sample,
            badShares: [$share],
        );
    }
}
