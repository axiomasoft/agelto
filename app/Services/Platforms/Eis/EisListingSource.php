<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisListingProbeFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Fetch\FetchBlocked;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummaries;
use App\Dto\Procurement\Acquisition\Listing\ListingFetched;
use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingPage;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\DecodedTextBody;
use App\Dto\Procurement\Network\Http\ResponseEnvelope;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Platform\Diagnostics\ExpectedResource;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Services\Platforms\Eis\Csv\EisCsvParser;
use App\Services\Platforms\Eis\Csv\EisSummaryParser;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\ResponseValidator;
use App\Services\Procurement\Platform\Contracts\TabularReader;
use App\Services\Procurement\Platform\Contracts\TabularSummaryMapper;
use Illuminate\Support\Str;

final readonly class EisListingSource implements ListingSource, ResponseValidator
{
    public function __construct(
        private EisPolicy $policy,
        private PlatformOutbound $outbound,
        private ProcessUuid $targetId,
        private ?ListingRuleSetDto $rules = null,
        private ?TabularReader $csvReader = null,
        private ?TabularSummaryMapper $summaryMapper = null,
    ) {}

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
        $rules = $this->rules ?? EisDefaultParsingRules::listing();
        $pageParam = $rules->transport->pageParam ?? 'from';
        $pageSizeParam = $rules->transport->pageSizeParam ?? 'to';
        $day = (new \DateTimeImmutable($cursor->day))->format('d.m.Y');
        $query = [...$filter->query(),
            $pageParam => (string) $cursor->firstRow, $pageSizeParam => (string) ($cursor->firstRow + $this->policy->blockSize - 1),
            'fz44' => EisDefaultParsingRules::LISTING_QUERY_FLAGS['fz44'],
            'sortBy' => $filter->sortBy,
            'sortDirection' => EisDefaultParsingRules::LISTING_QUERY_FLAGS['sortDirection'],
            $cursor->dateField.'DateFrom' => $day, $cursor->dateField.'DateTo' => $day,
            'priceFromGeneral' => EisListingCursor::decimal($cursor->lowCents),
            ...($cursor->highCents !== null
                ? ['priceToGeneral' => EisListingCursor::decimal($cursor->highCents)]
                : []),
            ...array_diff_key(EisDefaultParsingRules::LISTING_QUERY_FLAGS, ['fz44' => true, 'sortDirection' => true]),
        ];
        $endpoint = $this->policy->endpoints->get('listing');

        return new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), $this->targetId, $rules->transport->method,
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
        $rules = $this->rules ?? EisDefaultParsingRules::listing();
        if ($response->status !== 200 || ! preg_match(EisDefaultParsingRules::LISTING_CONTENT_TYPE_REGEX, $response->header('content-type') ?? '') || $body === '') {
            return $invalid(AssessmentReason::InvalidResponse);
        }
        if (strlen($body) > $this->policy->endpoints->get('listing')->profile->maxTextBytes) {
            return $invalid(AssessmentReason::Overflow);
        }
        $count = 0;
        try {
            $context?->tick();
            $batch = (new EisCsvParser($this->csvReader, $rules->csvDialect))->parseBatch($body, microtime(true) + $this->policy->listing->parserSeconds, tick: $context === null ? null : $context->tick(...));
            $count = $batch['records'];
            $requiredHeaders = $this->requiredHeaders($rules);
            if ($batch['header'] === null || array_diff($requiredHeaders, $batch['header']) !== []) {
                return $invalid(AssessmentReason::SchemaMismatch, $count);
            }
            $rows = $batch['rows'];
            if ($count > $this->policy->blockSize) {
                return $invalid(AssessmentReason::Overflow, $count);
            }
            $timezone = $rules->dateParsing->timezone !== '' ? $rules->dateParsing->timezone : $this->policy->sourceTimezone->getName();
            $parser = new EisSummaryParser(new EisSourceClock($timezone), $rules, $this->summaryMapper);
            $day = (new \DateTimeImmutable($cursor->day))->format('d.m.Y');
            $dateColumn = $this->dateColumnForField($rules, $cursor->dateField);
            $summaries = [];
            foreach ($rows as $row) {
                $context?->tick();
                $summary = $parser->parse($row);
                $dateValue = $row[$dateColumn];
                $priceMatches = true;
                if ($summary->initialPrice !== null) {
                    $price = explode('.', $summary->initialPrice);
                    $cents = (int) $price[0] * 100 + (int) str_pad($price[1] ?? '', 2, '0');
                    $priceMatches = $cents >= $cursor->lowCents && ($cursor->highCents === null || $cents <= $cursor->highCents);
                }
                if (! str_starts_with(trim($dateValue), $day) || ! $priceMatches
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

    /** @return list<string> */
    private function requiredHeaders(ListingRuleSetDto $rules): array
    {
        if ($rules->columns === null) {
            return EisDefaultParsingRules::LISTING_REQUIRED_HEADERS;
        }

        $headers = [];
        foreach ($rules->columns->columns as $column) {
            if ($column->required && isset($column->headerAliases[0])) {
                $headers[] = $column->headerAliases[0];
            }
        }

        return $headers !== [] ? $headers : EisDefaultParsingRules::LISTING_REQUIRED_HEADERS;
    }

    private function dateColumnForField(ListingRuleSetDto $rules, string $dateField): string
    {
        $target = $dateField === 'publish' ? 'publishedAt' : 'updatedAt';
        if ($rules->columns !== null) {
            foreach ($rules->columns->columns as $column) {
                if ($column->targetField === $target && isset($column->headerAliases[0])) {
                    return $column->headerAliases[0];
                }
            }
        }

        return $dateField === 'publish' ? 'Дата размещения' : 'Дата обновления';
    }
}
