<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Acquisition\ProcurementSummary;
use App\Dto\Procurement\Acquisition\SnapshotPart;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Dto\Procurement\Document\DocumentReference;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\ExpectedResource;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\ResponseValidator;
use Illuminate\Support\Str;

/** Testing-only JSON boundary. No dependency on the CSV/HTML adapter or its DTOs. */
final readonly class JsonProcurementSource implements ResponseValidator
{
    public function __construct(private PlatformDefinition $definition, private PlatformOutbound $outbound, private ProcessUuid $targetId) {}

    public function assess(ResponseEnvelope $response, ExpectedResource $expected): ResponseAssessment
    {
        if ($expected->request instanceof ListingRequest) {
            return $this->parseListing($expected->request, $response)->assessment;
        }
        $result = $this->parseDetail($expected->request, $response);
        if ($result instanceof FetchItemRejected) {
            return $result->assessment;
        }
        if (! $result instanceof DetailFetched) {
            throw new \LogicException('Pure detail parser returned a dependency outcome.');
        }

        return new ResponseAssessment($result->snapshot->complete() ? Completeness::Complete : Completeness::Partial,
            $result->snapshot->complete() ? AssessmentReason::Valid : AssessmentReason::MissingSection, $this->hash($response));
    }

    public function listingPlan(ListingRequest $request): OutboundRequestPlan
    {
        $this->listingRequest($request);
        $filter = $request->filter;
        if (! $filter instanceof JsonListingFilter) {
            throw new \InvalidArgumentException('Invalid JSON filter.');
        }

        return $this->plan('?'.http_build_query(['collection' => $filter->collection, 'after' => $request->cursor->serializedPayload]), TrafficClass::FreshListing);
    }

    public function fetchListing(ListingRequest $request, ExecutionContext $context): ListingFetchResult
    {
        $outcome = $this->outbound->execute($this->listingPlan($request), $context);

        return match (true) {
            $outcome instanceof TransportDeferred => new FetchDeferred($outcome->reason, $outcome->retryAt),
            $outcome instanceof TransportBlocked => new FetchBlocked($outcome->reason),
            $outcome instanceof TransportSucceeded => new ListingFetched($this->parseListing($request, $outcome->response)),
            default => throw new \LogicException('Unknown transport outcome.'),
        };
    }

    public function parseListing(ListingRequest $request, ResponseEnvelope $response): ListingPage
    {
        $this->listingRequest($request);
        $hash = $this->hash($response);
        $invalid = static fn (AssessmentReason $reason, int $rows = 0): ListingPage => new ListingPage(
            new ProcurementSummaries([]), $rows, new ResponseAssessment(Completeness::Partial, $reason, $hash), TerminalProof::None, null);
        if ($response->body instanceof DecodedTextBody && strlen($response->body->utf8) > 6_291_456) {
            return $invalid(AssessmentReason::Overflow);
        }
        try {
            $data = $this->body($response);
            $this->keys($data, ['notices', 'paging']);
            if (is_array($data['notices']) && array_is_list($data['notices']) && count($data['notices']) > 1000) {
                return $invalid(AssessmentReason::Overflow, count($data['notices']));
            }
            $rows = $this->list($data['notices']);
            $paging = $this->object($data['paging']);
            $this->keys($paging, ['next', 'complete', 'count']);
            if (! is_bool($paging['complete']) || ! is_int($paging['count']) || $paging['count'] !== count($rows)
                || (! is_string($paging['next']) && $paging['next'] !== null)
                || $paging['next'] === ''
                || $paging['complete'] !== ($paging['next'] === null)
                || ($rows === [] && ! $paging['complete'])) {
                throw new \UnexpectedValueException('Invalid JSON paging semantics.');
            }
            if (count($rows) > 1000) {
                return $invalid(AssessmentReason::Overflow, count($rows));
            }
            if ($paging['next'] === $request->cursor->serializedPayload) {
                return $invalid(AssessmentReason::RepeatedPage, count($rows));
            }
            $summaries = array_map(fn (mixed $row): ProcurementSummary => $this->summary($this->object($row)), $rows);
            $empty = $summaries === [];
            $items = new ProcurementSummaries($summaries);
            foreach ($request->observedPages as $previous) {
                if (! $empty && $previous->hex === $items->digest(false)->hex) {
                    return $invalid(AssessmentReason::RepeatedPage, count($rows));
                }
            }

            return new ListingPage(new ProcurementSummaries($summaries), count($rows),
                new ResponseAssessment($empty ? Completeness::CompleteEmpty : Completeness::Complete, $empty ? AssessmentReason::Empty : AssessmentReason::Valid, $hash),
                $empty ? TerminalProof::SemanticEmpty : ($paging['complete'] ? TerminalProof::ExplicitEnd : TerminalProof::None),
                $paging['next'] === null ? null : new CursorEnvelope('json.listing', 1, $paging['next']));
        } catch (\JsonException|\UnexpectedValueException|\InvalidArgumentException) {
            return $invalid(AssessmentReason::SchemaMismatch);
        }
    }

    public function fetchDetail(DetailRequest $request, ExecutionContext $context): DetailFetchResult
    {
        $this->definition->validateIdentity($request->registry, $request->identity);
        $outcome = $this->outbound->execute($this->plan('/'.rawurlencode($request->identity->value->value), TrafficClass::Detail), $context);

        return match (true) {
            $outcome instanceof TransportDeferred => new FetchDeferred($outcome->reason, $outcome->retryAt),
            $outcome instanceof TransportBlocked => new FetchBlocked($outcome->reason),
            $outcome instanceof TransportSucceeded => $this->parseDetail($request, $outcome->response),
            default => throw new \LogicException('Unknown transport outcome.'),
        };
    }

    public function parseDetail(DetailRequest $request, ResponseEnvelope $response): DetailFetchResult
    {
        $this->definition->validateIdentity($request->registry, $request->identity);
        try {
            $data = $this->body($response);
            $this->keys($data, ['notice', 'participants', 'attachments']);
            $summary = $this->summary($this->object($data['notice']));
            if ($summary->identity->toArray() !== $request->identity->toArray()) {
                return new FetchItemRejected(ItemFailureReason::ContractRejected, new ResponseAssessment(Completeness::Unavailable, AssessmentReason::IdentityMismatch, $this->hash($response)));
            }
            $parties = [];
            foreach ($this->list($data['participants']) as $raw) {
                $row = $this->object($raw);
                $this->keys($row, ['role', 'name', 'organizationKey']);
                $role = ProcurementPartyRole::tryFrom($this->string($row['role']));
                if ($role === null) {
                    throw new \UnexpectedValueException('Invalid JSON party role.');
                }
                $parties[] = new PartyData($role, new OrganizationData($this->string($row['name']),
                    identifiers: [new OrganizationIdentifierData('json', 'organization', $this->string($row['organizationKey']))]));
            }
            $documents = [];
            foreach ($this->list($data['attachments']) as $raw) {
                $row = $this->object($raw);
                $this->keys($row, ['key', 'name', 'signedUrl', 'expires']);
                $documents[] = new DocumentReference($summary->identity, $this->string($row['key']), $this->string($row['name']),
                    new SafeUrl($this->string($row['signedUrl'])), $this->instant($row['expires']));
            }
            $parts = [];
            foreach (['attributes' => 1, 'parties' => count($parties), 'documents' => count($documents)] as $name => $count) {
                $parts[] = new SnapshotPart($name, $count === 0 ? Completeness::CompleteEmpty : Completeness::Complete, true, $this->hash($response), new \DateTimeImmutable);
            }

            return new DetailFetched(new ProcurementSnapshot($summary->identity, $summary->title, $summary->sourceStage,
                $parties, [], [], $documents, $parts, sourceRevision: $summary->sourceRevision));
        } catch (\JsonException|\UnexpectedValueException|\InvalidArgumentException) {
            return new FetchItemRejected(ItemFailureReason::ContractRejected, new ResponseAssessment(Completeness::Unavailable, AssessmentReason::SchemaMismatch, $this->hash($response)));
        }
    }

    private function plan(string $suffix, TrafficClass $traffic): OutboundRequestPlan
    {
        $endpoint = $this->definition->endpoints->get('detail');

        return new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), $this->targetId, 'GET',
            new SafeUrl($endpoint->url.$suffix), $endpoint->profile, $traffic, IdempotencyClass::ReadOnly);
    }

    private function listingRequest(ListingRequest $request): void
    {
        $this->definition->registries->get($request->registry);
        if (! $request->filter instanceof JsonListingFilter || $request->cursor->codecId !== 'json.listing' || $request->cursor->schemaVersion !== 1) {
            throw new \InvalidArgumentException('Invalid JSON listing request.');
        }
    }

    /** @param array<string,mixed> $row */
    private function summary(array $row): ProcurementSummary
    {
        $this->keys($row, ['key', 'label', 'phase', 'published', 'changed', 'cost', 'currency', 'version']);
        $identity = new SourceIdentity($this->definition->code, $this->definition->registries->get('fz44')->primaryIdentityType, new SourceIdentifierValue($this->string($row['key'])));
        ksort($row, SORT_STRING);

        return new ProcurementSummary($identity, $this->string($row['label']), $this->string($row['phase']),
            $this->instant($row['published']), $this->instant($row['changed']), ContentHash::of(json_encode($row, JSON_THROW_ON_ERROR)),
            $this->string($row['version']), $this->string($row['cost']), $this->string($row['currency']));
    }

    /** @return array<string,mixed> */
    private function body(ResponseEnvelope $response): array
    {
        if ($response->status !== 200 || ! preg_match('~^application/json(?:;|$)~i', $response->header('content-type') ?? '')
            || ! $response->body instanceof DecodedTextBody || strlen($response->body->utf8) > 6_291_456) {
            throw new \UnexpectedValueException('Invalid JSON response.');
        }

        return $this->object(json_decode($response->body->utf8, true, 32, JSON_THROW_ON_ERROR));
    }

    private function hash(ResponseEnvelope $response): ContentHash
    {
        return ContentHash::of($response->body instanceof DecodedTextBody ? $response->body->utf8 : '');
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<string>  $keys
     */
    private function keys(array $data, array $keys): void
    {
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new \UnexpectedValueException('Invalid JSON object schema.');
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('Expected JSON object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('Expected named JSON keys.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 1001) {
            throw new \UnexpectedValueException('Expected bounded JSON array.');
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 262144) {
            throw new \UnexpectedValueException('Expected nonempty JSON string.');
        }

        return $value;
    }

    private function instant(mixed $value): \DateTimeImmutable
    {
        if (! is_int($value) || $value < 0 || $value > 4102444800) {
            throw new \UnexpectedValueException('Expected bounded epoch seconds.');
        }

        return new \DateTimeImmutable('@'.$value);
    }
}
