<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisListingProbeFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\ExpectedResource;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Services\Platforms\Eis\Csv\EisCsvParser;
use App\Services\Platforms\Eis\Csv\EisSummaryParser;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\ResponseValidator;
use Illuminate\Support\Str;

final readonly class EisListingSource implements ListingSource, ResponseValidator
{
    public function __construct(private EisPolicy $policy, private PlatformOutbound $outbound, private ProcessUuid $targetId) {}

    public function assess(ResponseEnvelope $response, ExpectedResource $expected): ResponseAssessment
    {
        if (! $expected->request instanceof ListingRequest) {
            throw new \InvalidArgumentException('Listing validator requires a listing request.');
        }

        return $this->parse($expected->request, $response)->assessment;
    }

    public function plan(ListingRequest $request): OutboundRequestPlan
    {
        $cursor = $this->cursor($request);
        $filter = $request->filter;
        if (! $filter instanceof EisListingFilter && ! $filter instanceof EisListingProbeFilter) {
            throw new \InvalidArgumentException('Expected EIS filter.');
        }
        $day = (new \DateTimeImmutable($cursor->day))->format('d.m.Y');
        $query = [...$filter->query(),
            'from' => (string) $cursor->firstRow, 'to' => (string) ($cursor->firstRow + $this->policy->blockSize - 1),
            'fz44' => 'on', 'sortBy' => $filter->sortBy, 'sortDirection' => 'true',
            $cursor->dateField.'DateFrom' => $day, $cursor->dateField.'DateTo' => $day,
            'priceFromGeneral' => EisListingCursor::decimal($cursor->lowCents),
            'priceToGeneral' => EisListingCursor::decimal($cursor->highCents),
            'registryNumberCsv' => 'on', 'publishDateCsv' => 'on', 'lastDateChangeCsv' => 'on',
            'maxContractPriceCsv' => 'on', 'nameOrderCsv' => 'on', 'stepOrderPlacementCsv' => 'on',
            'currencyCodeCsv' => 'on', 'methodOrderPurchaseCsv' => 'on',
            'purchaseNumbersCsv' => 'on', 'startDateRequestCsv' => 'on', 'endDateRequestCsv' => 'on', 'customerNameCsv' => 'on',
        ];
        $endpoint = $this->policy->endpoints->get('listing');

        return new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), $this->targetId, 'GET',
            new SafeUrl($endpoint->url.'?'.http_build_query($query)), $endpoint->profile,
            $request->kind === AcquisitionKind::Archive ? TrafficClass::ArchiveListing : TrafficClass::FreshListing, IdempotencyClass::ReadOnly);
    }

    public function fetch(ListingRequest $request, ExecutionContext|OutboundExecutionContext $context): ListingFetchResult
    {
        if ($request->filter instanceof EisListingProbeFilter && ! $context instanceof OutboundExecutionContext) {
            throw new \InvalidArgumentException('Unfiltered listing comparison requires a diagnostic operation.');
        }
        $outcome = $this->outbound->execute($this->plan($request), $context);

        return match (true) {
            $outcome instanceof TransportDeferred => new FetchDeferred($outcome->reason, $outcome->retryAt),
            $outcome instanceof TransportBlocked => new FetchBlocked($outcome->reason),
            $outcome instanceof TransportSucceeded => new ListingFetched($this->parse($request, $outcome->response, $context)),
            default => throw new \LogicException('Unknown transport outcome.'),
        };
    }

    public function parse(ListingRequest $request, ResponseEnvelope $response, ExecutionContext|OutboundExecutionContext|null $context = null): ListingPage
    {
        $cursor = $this->cursor($request);
        $body = $response->body instanceof DecodedTextBody ? $response->body->utf8 : '';
        $filter = $request->filter;
        if (! $filter instanceof EisListingFilter && ! $filter instanceof EisListingProbeFilter) {
            throw new \InvalidArgumentException('Expected EIS filter.');
        }
        $hash = ContentHash::of($body);
        $invalid = static fn (AssessmentReason $reason, int $rows = 0, ?FailureScope $failureScope = null): ListingPage => new ListingPage(
            new ProcurementSummaries([]), $rows, new ResponseAssessment(Completeness::Partial, $reason, $hash, failureScope: $failureScope), TerminalProof::None, null);
        if ($response->status !== 200 || ! preg_match('~^(?:text/(?:csv|plain)|application/(?:csv|octet-stream))(?:;|$)~i', $response->header('content-type') ?? '') || $body === '') {
            return $invalid(AssessmentReason::InvalidResponse);
        }
        if (strlen($body) > $this->policy->endpoints->get('listing')->profile->maxTextBytes) {
            return $invalid(AssessmentReason::Overflow);
        }
        $count = 0;
        try {
            $context?->tick();
            $batch = (new EisCsvParser)->parseBatch($body, microtime(true) + $this->policy->listing->parserSeconds, tick: $context === null ? null : $context->tick(...));
            $count = $batch['records'];
            if ($batch['header'] === null || array_diff(
                ['Реестровый номер закупки', 'Дата размещения', 'Дата обновления', 'Начальная (максимальная) цена контракта'], $batch['header']) !== []) {
                return $invalid(AssessmentReason::SchemaMismatch, $count);
            }
            $rows = $batch['rows'];
            if ($count > $this->policy->blockSize) {
                return $invalid(AssessmentReason::Overflow, $count);
            }
            $parser = new EisSummaryParser(new EisSourceClock($this->policy->sourceTimezone->getName()));
            $summaries = [];
            foreach ($rows as $row) {
                $context?->tick();
                $summary = $parser->parse($row);
                $dateValue = $row[$cursor->dateField === 'publish' ? 'Дата размещения' : 'Дата обновления'];
                $day = (new \DateTimeImmutable($cursor->day))->format('d.m.Y');
                $price = explode('.', $summary->initialPrice ?? '');
                $cents = (int) $price[0] * 100 + (int) str_pad($price[1] ?? '', 2, '0');
                if (! str_starts_with(trim($dateValue), $day) || $cents < $cursor->lowCents || $cents > $cursor->highCents
                    || ! $filter->acceptsStage($summary->sourceStage)) {
                    return $invalid(AssessmentReason::FilterMismatch, $count);
                }
                $summaries[] = $summary;
            }
            if ($batch['malformed'] > 0) {
                // A validated row proves the header/parser contract; isolated malformed records
                // still invalidate this whole block, without indicting the adapter version.
                return $invalid(AssessmentReason::SchemaMismatch, $count,
                    $summaries === [] ? FailureScope::Adapter : FailureScope::Item);
            }
        } catch (SourceResponseLimitExceeded|OutboundRequestDeadlineExceeded) {
            return $invalid(AssessmentReason::Overflow);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return $invalid(AssessmentReason::SchemaMismatch, $count);
        }
        $items = new ProcurementSummaries($summaries);
        foreach ($request->observedPages as $previous) {
            if ($count > 0 && $previous->hex === $items->digest(false)->hex) {
                return new ListingPage($items, $count, new ResponseAssessment(Completeness::Partial, AssessmentReason::RepeatedPage, $hash), TerminalProof::None, null);
            }
        }
        $terminal = $count === 0 ? TerminalProof::SemanticEmpty : ($count < $this->policy->blockSize ? TerminalProof::ShortPage : TerminalProof::None);
        if ($terminal === TerminalProof::None && $cursor->firstRow + $count > $this->policy->listing->maxBlocks * $this->policy->blockSize) {
            return new ListingPage($items, $count, new ResponseAssessment(Completeness::Partial, AssessmentReason::Overflow, $hash), TerminalProof::None, null);
        }

        return new ListingPage($items, $count,
            new ResponseAssessment($count === 0 ? Completeness::CompleteEmpty : Completeness::Complete, $count === 0 ? AssessmentReason::Empty : AssessmentReason::Valid, $hash),
            $terminal, $terminal === TerminalProof::None ? $cursor->next($this->policy->blockSize)->envelope() : null);
    }

    private function cursor(ListingRequest $request): EisListingCursor
    {
        if ($request->registry !== 'fz44' || (! $request->filter instanceof EisListingFilter && ! $request->filter instanceof EisListingProbeFilter)) {
            throw new \InvalidArgumentException('EIS registry/filter mismatch.');
        }
        $cursor = EisListingCursor::decode($request->cursor);
        if ($cursor->filterHash->hex !== $request->filter->fingerprint()->hex || $cursor->blockSize !== $this->policy->blockSize) {
            throw new \InvalidArgumentException('EIS cursor does not belong to this filter/policy.');
        }

        return $cursor;
    }
}
