<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Exceptions\Procurement\UnknownReportFormat;

/**
 * Общие куски команд с `--format=json|md` и хвостом `--out` (§5 `root/service-layer-map.md`,
 * P3.1): гвард формата, рендер JSON, запись артефакта по репо-относительному пути.
 */
trait RendersReport
{
    /**
     * @throws UnknownReportFormat
     */
    private function guardFormat(string $format): void
    {
        if (! in_array($format, ['json', 'md'], true)) {
            throw new UnknownReportFormat($format);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderJson(array $report): string
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function writeArtifact(?string $out, string $rendered): void
    {
        if ($out !== null) {
            file_put_contents(base_path($out), $rendered);
        }
    }
}
