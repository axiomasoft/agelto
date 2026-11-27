<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Diagnostics;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingProbeFilter;
use App\Dto\Procurement\Acquisition\Control\AcquisitionLaunch;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Acquisition\Listing\ListingPage;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Dto\Procurement\Platform\Diagnostics\DiagnosticInput;
use App\Dto\Procurement\Platform\Diagnostics\ProbePlan;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Network\Contracts\DiagnosticCheck;
use LogicException;

/**
 * Проверяет ДЕЙСТВИЕ серверного фильтра ОКПД2 двумя блоками того же CSV-пути, что использует
 * обход. Прямой HTTP-запрос здесь намеренно запрещён: он не заметит поломку builder'а, proxy/TLS
 * или budget-цепочки. Невозможность выполнить запрос (бюджет, прокси, сеть) не доказывает
 * поломку ЕИС, поэтому возвращается `not_enough_data`, а не `broken`.
 */
final readonly class ListingFilterCheck implements DiagnosticCheck
{
    public function plan(AcquisitionLaunch $launch): ProbePlan
    {
        $filter = (new EisListingPartitioner)->restoreFilter($launch->filter);
        $window = $launch->plan->windows[0] ?? throw new \InvalidArgumentException('No bounded listing window to validate.');
        $cursor = $window->cursor;

        $unfiltered = new EisListingProbeFilter($filter);
        $decoded = EisListingCursor::decode($cursor);
        $probeCursor = new EisListingCursor($decoded->day, $decoded->dateField, $decoded->lowCents,
            $decoded->highCents, $unfiltered->fingerprint(), 1, 0, $decoded->blockSize);

        return new ProbePlan([
            new ListingRequest($launch->registry, $unfiltered, $probeCursor->envelope()),
            new ListingRequest($launch->registry, $filter, $cursor),
        ]);
    }

    /** Pure new-boundary assessment; acquisition diagnostics supplies two bounded reads. */
    public static function assessPages(
        ListingPage $unfiltered,
        ListingPage $filtered,
        float $maxOverlapShare,
    ): ResponseAssessment {
        if (! is_finite($maxOverlapShare) || $maxOverlapShare < 0 || $maxOverlapShare > 1) {
            throw new \InvalidArgumentException('Invalid filter overlap threshold.');
        }
        $hash = ContentHash::of($unfiltered->assessment->bodyHash->hex.$filtered->assessment->bodyHash->hex);
        if (! $unfiltered->assessment->usable() || ! $filtered->assessment->usable()
            || $unfiltered->summaries->items === [] || $filtered->summaries->items === []) {
            return new ResponseAssessment(
                Completeness::Unavailable,
                AssessmentReason::InvalidResponse, $hash);
        }
        $identities = static fn (ListingPage $page): array => array_unique(array_map(
            static fn (ProcurementSummary $summary): string => json_encode($summary->identity->toArray(), JSON_THROW_ON_ERROR),
            $page->summaries->items,
        ));
        $all = $identities($unfiltered);
        $share = count(array_intersect($all, $identities($filtered))) / count($all);

        return new ResponseAssessment(
            $share > $maxOverlapShare ? Completeness::Partial : Completeness::Complete,
            $share > $maxOverlapShare ? AssessmentReason::FilterMismatch : AssessmentReason::Valid, $hash);
    }

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::ListingFilter;
    }

    public function run(DiagnosticInput $input): CheckResult
    {
        $baseline = $input->baseline;
        $requestsUsed = $input->requestsUsed;
        if (count($input->pages) !== 2 || ! $input->pages[0]->assessment->usable()
            || ! $input->pages[1]->assessment->usable()) {
            return $this->notEnoughData('Проба фильтра не получила два проверенных listing ответа.'.($input->unavailable === null ? '' : ' '.$input->unavailable->value),
                $requestsUsed, $this->measured($input));
        }
        [$unfiltered, $filtered] = $input->pages;
        $unfilteredNumbers = $this->registryNumbers($unfiltered);
        $filteredNumbers = $this->registryNumbers($filtered);
        $overlap = count(array_intersect($unfilteredNumbers, $filteredNumbers));
        $overlapShare = $unfilteredNumbers === [] ? 1.0 : $overlap / count($unfilteredNumbers);
        $measured = [
            ...$this->measured($input),
            'rows_unfiltered' => $unfiltered->rowsTotal,
            'rows_filtered' => $filtered->rowsTotal,
            'registry_numbers_unfiltered' => count($unfilteredNumbers),
            'registry_numbers_filtered' => count($filteredNumbers),
            'overlap' => $overlap,
            'overlap_share' => (float) $overlapShare,
        ];

        if ($filteredNumbers === []) {
            return new CheckResult(
                kind: $this->kind(),
                verdict: PlatformCheckVerdict::Broken,
                measured: $measured,
                sampleSize: count($unfilteredNumbers),
                requestsUsed: $requestsUsed,
                message: 'Фильтр ОКПД2 отсёк весь блок либо карта id устарела.',
            );
        }

        if ($unfilteredNumbers === []) {
            return new CheckResult(
                kind: $this->kind(),
                verdict: PlatformCheckVerdict::Broken,
                measured: $measured,
                sampleSize: 0,
                requestsUsed: $requestsUsed,
                message: 'Нефильтрованный блок не содержит реестровых номеров.',
            );
        }

        $threshold = $this->maxOverlapShare(baseline: $baseline);
        $measured['max_overlap_share'] = $threshold;
        $calibrationNote = $baseline->calibrated ? '' : '; порог некалиброван';

        return new CheckResult(
            kind: $this->kind(),
            verdict: $overlapShare > $threshold ? PlatformCheckVerdict::Broken : PlatformCheckVerdict::Ok,
            measured: $measured,
            sampleSize: count($unfilteredNumbers),
            requestsUsed: $requestsUsed,
            message: sprintf(
                'Пересечение реестровых номеров: %d/%d (%.3f), порог %.3f%s.',
                $overlap,
                count($unfilteredNumbers),
                $overlapShare,
                $threshold,
                $calibrationNote,
            ),
        );
    }

    /** @return array<string, int|string> */
    private function measured(DiagnosticInput $input): array
    {
        return [
            'crawler_id' => $input->profileId ?? 0,
            'window_from' => $input->from->format(DATE_ATOM),
            'window_to' => $input->to->format(DATE_ATOM),
        ];
    }

    /** @return list<string> */
    private function registryNumbers(ListingPage $page): array
    {
        return array_values(array_unique(array_map(static fn (ProcurementSummary $summary): string => json_encode($summary->identity->toArray(), JSON_THROW_ON_ERROR), $page->summaries->items)));
    }

    private function maxOverlapShare(HealthBaseline $baseline): float
    {
        $threshold = $baseline->thresholds[$this->kind()->value]['max_overlap_share'] ?? null;

        if (! is_numeric($threshold)) {
            throw new LogicException('В эталоне отсутствует порог пересечения listing_filter.');
        }

        return (float) $threshold;
    }

    /** @param array<string, mixed> $measured */
    private function notEnoughData(string $message, int $requestsUsed, array $measured): CheckResult
    {
        return new CheckResult(
            kind: $this->kind(),
            verdict: PlatformCheckVerdict::NotEnoughData,
            measured: $measured,
            sampleSize: 0,
            requestsUsed: $requestsUsed,
            message: $message,
        );
    }
}
