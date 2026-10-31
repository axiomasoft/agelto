<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\SnapshotPart;
use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Document\DocumentReference;
use App\Dto\Procurement\Document\SourceDocumentKey;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\ProtocolType;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\StatusSource;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

final readonly class EisDetailSource implements DetailPartsSource, StatusSource
{
    public function __construct(private EisPolicy $policy, private EisCardRoutes $routes,
        private EisDetailParser $parser, private PlatformOutbound $outbound, private ProcessUuid $targetId) {}

    public function refresh(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        return $this->fetchPart(new DetailRequest($request->registry, $request->identity, $request->locator, statusOnly: true), 'common-info', $context);
    }

    /** @return array<string,OutboundRequestPlan|null> */
    public function plans(DetailRequest $request): array
    {
        $procedure = match ($request->locator?->codecId) {
            'eis.card' => $request->locator->serializedPayload,
            'eis.procedure' => $this->routes->procedureTypes[$request->locator->serializedPayload] ?? null,
            default => null,
        };
        if ($request->registry !== 'fz44' || $request->identity->platform->value !== 'eis'
            || $request->identity->type->value !== 'registry:44fz' || $request->locator?->schemaVersion !== 1
            || $procedure === null || ! isset($this->routes->procedures[$procedure])) {
            throw new \InvalidArgumentException('EIS detail identity/locator mismatch.');
        }
        $endpoint = $this->policy->endpoints->get('card');
        $plans = [];
        foreach ($this->routes->procedures[$procedure] as $tab => $url) {
            if ($url === null) {
                $plans[$tab] = null;

                continue;
            }
            $safe = new SafeUrl(str_replace('{regNumber}', rawurlencode($request->identity->value->value), $url));
            if (! $safe->origin->equals((new SafeUrl($endpoint->url))->origin)) {
                throw new \InvalidArgumentException('EIS card route origin mismatch.');
            }
            $plans[$tab] = new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), $this->targetId, 'GET',
                $safe, $endpoint->profile, $request->statusOnly ? TrafficClass::StatusRefresh : TrafficClass::Detail, IdempotencyClass::ReadOnly);
        }

        return $plans;
    }

    public function fetch(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        $responses = [];
        foreach ($this->plans($request) as $tab => $plan) {
            if ($plan === null) {
                continue;
            }
            $context->tick();
            $outcome = $this->outbound->execute($plan, $context);
            if ($outcome instanceof TransportDeferred) {
                return new FetchDeferred($outcome->reason, $outcome->retryAt);
            }
            if ($outcome instanceof TransportBlocked) {
                return new FetchBlocked($outcome->reason);
            }
            if (! $outcome instanceof TransportSucceeded) {
                throw new \LogicException('Unknown transport outcome.');
            }
            $responses[$tab] = $outcome->response;
        }

        return $this->parse($request, $responses);
    }

    public function parts(DetailRequest $request): array
    {
        return ['common-info', 'documents', 'event-journal', 'supplier-results'];
    }

    public function fetchPart(DetailRequest $request, string $part, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        $plans = $this->plans($request);
        if (! array_key_exists($part, $plans)) {
            throw new \InvalidArgumentException('Unknown EIS part.');
        }
        $responses = [];
        if ($plans[$part] !== null) {
            $context->tick();
            $outcome = $this->outbound->execute($plans[$part], $context);
            if ($outcome instanceof TransportDeferred) {
                return new FetchDeferred($outcome->reason, $outcome->retryAt);
            }
            if ($outcome instanceof TransportBlocked) {
                return new FetchBlocked($outcome->reason);
            }
            if (! $outcome instanceof TransportSucceeded) {
                throw new \LogicException('Unknown transport outcome.');
            }
            $responses[$part] = $outcome->response;
        }
        $outcome = $this->parse($request, $responses);
        if (! $outcome instanceof DetailFetched) {
            return $outcome;
        }
        $s = $outcome->snapshot;
        $names = match ($part) {
            'common-info' => ['attributes', 'parties'], 'documents' => ['documents'],
            'event-journal' => ['events'], 'supplier-results' => ['results'],
            default => throw new \InvalidArgumentException('Unknown EIS part.'),
        };

        return new DetailFetched(new ProcurementSnapshot($s->identity, $s->title, $s->sourceStage,
            $s->parties, $s->items, $s->financing, $s->documents,
            array_values(array_filter($s->parts, static fn (SnapshotPart $p): bool => in_array($p->name, $names, true))),
            $s->extension, $s->sourceRevision, $s->events, $s->results));
    }

    /** @param array<string,ResponseEnvelope> $responses */
    public function parse(DetailRequest $request, array $responses): DetailFetchResult
    {
        $plans = $this->plans($request);
        if (array_diff(array_keys($responses), array_keys($plans)) !== []) {
            throw new \InvalidArgumentException('Unexpected EIS response part.');
        }
        $parts = [];
        $common = new CommonInfoData;
        $parties = $documents = $events = [];
        $results = null;
        $title = $stage = '';
        foreach ($plans as $tab => $plan) {
            $section = match ($tab) {
                'common-info' => 'attributes', 'documents' => 'documents',
                'event-journal' => 'events', 'supplier-results' => 'results',
                default => throw new \LogicException('Unknown EIS tab.'),
            };
            $response = $responses[$tab] ?? null;
            $body = $response?->body instanceof DecodedTextBody ? $response->body->utf8 : '';
            $hash = ContentHash::of($body);
            $fetchedAt = new \DateTimeImmutable;
            if ($plan === null) {
                if ($response !== null) {
                    throw new \InvalidArgumentException('Response supplied for inapplicable EIS part.');
                }
                $parts[] = new SnapshotPart($section, Completeness::NotApplicable, false, $hash, $fetchedAt);

                continue;
            }
            if ($response === null || $response->status !== 200 || $body === ''
                || strlen($body) > $this->policy->endpoints->get('card')->profile->maxTextBytes
                || ! preg_match('~^text/html(?:;|$)~i', $response->header('content-type') ?? '')) {
                $parts[] = new SnapshotPart($section, Completeness::Unavailable, true, $hash, $fetchedAt);

                continue;
            }
            $dom = new Crawler($body);
            $identity = $dom->filter('.cardMainInfo__purchaseLink');
            if ($identity->count() !== 1) {
                $parts[] = new SnapshotPart($section, Completeness::Partial, true, $hash, $fetchedAt);

                continue;
            }
            if (trim(preg_replace('/^№\\s*/u', '', trim($identity->text())) ?? '') !== $request->identity->value->value) {
                return new FetchItemRejected(ItemFailureReason::ContractRejected,
                    new ResponseAssessment(Completeness::Unavailable, AssessmentReason::IdentityMismatch, $hash));
            }
            $complete = false;
            $count = 0;
            try {
                switch ($tab) {
                    case 'common-info':
                        $snapshot = $this->parser->commonInfo($body, strict: true);
                        $common = $snapshot->commonInfo;
                        $parties = $snapshot->parties;
                        $complete = $snapshot->authorizesCommonInfoReconciliation();
                        $titleNode = $dom->filter('.cardMainInfo__purchaseObject');
                        $stageNode = $dom->filter('.cardMainInfo__state');
                        $title = $titleNode->count() === 1 ? trim($titleNode->text()) : '';
                        $stage = $stageNode->count() === 1 ? trim($stageNode->text()) : '';
                        $complete = $complete && $title !== '' && $stage !== '';
                        $count = 1;
                        $partiesComplete = $snapshot->partiesComplete && ($parties !== [] || $dom->filter('section.organizations-block table.parties-table')->count() === 1);
                        $parts[] = new SnapshotPart('parties', $partiesComplete
                            ? ($parties === [] ? Completeness::CompleteEmpty : Completeness::Complete)
                            : Completeness::Partial, true, $hash, $fetchedAt);
                        break;
                    case 'documents':
                        $snapshot = $this->parser->documents($body, strict: true);
                        $complete = $snapshot->authorizesReconciliation();
                        foreach ($snapshot->documents as $document) {
                            if ($document->sourceUrl === null || $document->externalId === null || $document->title === '') {
                                $complete = false;

                                continue;
                            }
                            $documents[] = new DocumentReference($request->identity, $document->externalId, $document->title, new SafeUrl($document->sourceUrl),
                                new SourceDocumentKey(json_encode(
                                    ['attachment', $request->identity->type->value, $request->identity->value->value, $document->externalId], JSON_THROW_ON_ERROR), 1));
                        }
                        $count = count($documents);
                        break;
                    case 'event-journal':
                        $events = $this->parser->eventJournal($body, strict: true);
                        $count = count($events);
                        $complete = $dom->filter('table#event')->count() === 1 && $dom->filter('table#event tbody tr')->count() === $count;
                        break;
                    case 'supplier-results':
                        $results = $this->parser->supplierResults($body, strict: true);
                        $count = count($results->bids);
                        // An unrecognized/empty layout is partial, never an implicit NotApplicable.
                        $rows = $dom->filter('div[id^="supplier-def-result-participant-table-"] table.tableBlock tbody tr')->count();
                        $complete = $results->protocol !== null && $rows === $count
                            && ($count > 0 || $results->protocol->type === ProtocolType::Failed->value);
                        break;
                }
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                return new FetchItemRejected(ItemFailureReason::ContractRejected,
                    new ResponseAssessment(Completeness::Unavailable, AssessmentReason::SchemaMismatch, $hash));
            }
            $parts[] = new SnapshotPart($section, $complete ? ($count === 0 ? Completeness::CompleteEmpty : Completeness::Complete) : Completeness::Partial, true, $hash, $fetchedAt);
        }

        return new DetailFetched(new ProcurementSnapshot($request->identity, $title, $stage, $parties, $common->items,
            $common->financingRows, $documents, $parts, $common->extension, events: $events, results: $results));
    }
}
