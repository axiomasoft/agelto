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
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\ProtocolType;
use App\Services\Platforms\Eis\Card\CardDomHelper;
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
        $plans = $this->planTabs($request);
        if ($plans instanceof FetchItemRejected) {
            throw new \InvalidArgumentException('Unmapped EIS detail procedure.');
        }

        return $plans;
    }

    /** @return array<string,OutboundRequestPlan|null>|FetchItemRejected */
    private function planTabs(DetailRequest $request): array|FetchItemRejected
    {
        $locator = $request->locator;
        if ($request->registry !== 'fz44' || $request->identity->platform->value !== 'eis'
            || $request->identity->type->value !== 'registry:44fz' || $locator?->schemaVersion !== 1
            || ! in_array($locator->codecId, ['eis.card', 'eis.procedure'], true)) {
            throw new \InvalidArgumentException('EIS detail identity/locator mismatch.');
        }
        $procedure = match ($locator->codecId) {
            'eis.card' => $locator->serializedPayload,
            default => $this->routes->procedureTypes[$locator->serializedPayload] ?? null,
        };
        if ($procedure === null || ! isset($this->routes->procedures[$procedure])) {
            $evidence = json_encode(['identity' => $request->identity->toArray(), 'locator_sha256' => $locator->payloadHash->hex,
                'reason' => 'unmapped_procedure'], JSON_THROW_ON_ERROR);

            return new FetchItemRejected(reason: ItemFailureReason::InvalidInput,
                assessment: new ResponseAssessment(Completeness::Unavailable, AssessmentReason::SchemaMismatch,
                    ContentHash::of($evidence), failureScope: FailureScope::Item), inputEvidence: $evidence);
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
        $plans = $this->planTabs($request);
        if ($plans instanceof FetchItemRejected) {
            return $plans;
        }
        $responses = [];
        foreach ($plans as $tab => $plan) {
            if ($plan === null) {
                continue;
            }
            $response = $this->executePlan($plan, $tab, $request, $context);
            if (! $response instanceof ResponseEnvelope) {
                return $response;
            }
            $responses[$tab] = $response;
        }

        return $this->parse($request, $responses);
    }

    public function parts(DetailRequest $request): array
    {
        return ['common-info', 'documents', 'event-journal', 'supplier-results'];
    }

    public function fetchPart(DetailRequest $request, string $part, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        $plans = $this->planTabs($request);
        if ($plans instanceof FetchItemRejected) {
            return $plans;
        }
        if (! array_key_exists($part, $plans)) {
            throw new \InvalidArgumentException('Unknown EIS part.');
        }
        $responses = [];
        if ($plans[$part] !== null) {
            $response = $this->executePlan($plans[$part], $part, $request, $context);
            if (! $response instanceof ResponseEnvelope) {
                return $response;
            }
            $responses[$part] = $response;
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
            $s->extension, $s->sourceRevision, $s->events, $s->results, initialPrice: $s->initialPrice));
    }

    private function executePlan(OutboundRequestPlan $plan, string $tab, DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): ResponseEnvelope|FetchDeferred|FetchBlocked
    {
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
        if (in_array($outcome->response->status, [403, 429], true)) {
            CardDomHelper::logWarning('Карточка ЕИС временно недоступна из-за ограничения частоты запросов (WAF)', [
                'reg_number' => $request->identity->value->value, 'tab' => $tab, 'http_status' => $outcome->response->status,
            ]);

            return new FetchDeferred(reason: NetworkDeferralReason::RateLimit,
                retryAt: new RetryAt($context->dbNow()->value->add(new \DateInterval('PT60S'))));
        }

        return $outcome->response;
    }

    /** @param array<string,ResponseEnvelope> $responses */
    public function parse(DetailRequest $request, array $responses): DetailFetchResult
    {
        $plans = $this->planTabs($request);
        if ($plans instanceof FetchItemRejected) {
            return $plans;
        }
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
            $partHash = $hash;
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
                        if ($titleNode->count() !== 1) {
                            $titleNode = $dom->filter('.cardMainInfo__section')
                                ->reduce(static fn (Crawler $s): bool => $s->filter('.cardMainInfo__title')->count() === 1
                                    && trim($s->filter('.cardMainInfo__title')->text()) === 'Объект закупки')
                                ->filter('.cardMainInfo__content');
                        }
                        $stageNode = $dom->filter('.cardMainInfo__state');
                        $title = $titleNode->count() === 1 ? trim($titleNode->text()) : '';
                        if ($title === '' && isset($common->items[0])) {
                            $title = trim($common->items[0]->name);
                        }
                        $stage = $stageNode->count() === 1 ? trim($stageNode->text()) : '';
                        $complete = $complete && $title !== '' && $stage !== '';
                        $count = 1;
                        $partiesComplete = $snapshot->partiesComplete && ($parties !== [] || $dom->filter('section.organizations-block table.parties-table')->count() === 1);
                        $partHash = ContentHash::of(json_encode([$title, $stage, $common, $parties], JSON_THROW_ON_ERROR));
                        $parts[] = new SnapshotPart('parties', $partiesComplete
                            ? ($parties === [] ? Completeness::CompleteEmpty : Completeness::Complete)
                            : Completeness::Partial, true, $partHash, $fetchedAt);
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
                        $tables = $dom->filter('div[id^="supplier-def-result-participant-table-"] table.tableBlock');
                        $rows = $tables->filter('tbody tr')->count();
                        $failedReason = mb_strtolower(CardDomHelper::readDefinitionValue($dom, 'Основание признания торгов несостоявшимися') ?? '');
                        $noBidsExpected = str_contains($failedReason, 'не подано ни одной заявки')
                            || str_contains($failedReason, 'все поданные заявки отклонены')
                            || str_contains($failedReason, 'заявка не соответствует требованиям')
                            || str_contains($failedReason, 'признан уклонившимся')
                            || str_contains($failedReason, 'признаны уклонившимися')
                            || str_contains($failedReason, 'отказался от заключения контракта');
                        // Publication supports one group of bids and one award, with no lot identity.
                        $ambiguous = $tables->count() > 1 || $results->hasMultipleWinners();
                        $complete = ! $ambiguous && $results->protocol !== null && $rows === $count
                            && ($count > 0 || $results->protocol->type === ProtocolType::Failed->value || $noBidsExpected);
                        break;
                }
            } catch (\InvalidArgumentException|\UnexpectedValueException $failure) {
                CardDomHelper::logWarning('Вкладка карточки ЕИС отклонена строгим контрактом парсера', [
                    'reg_number' => $request->identity->value->value,
                    'tab' => $tab,
                    'exception' => $failure::class,
                    'file' => basename($failure->getFile()),
                    'line' => $failure->getLine(),
                    'error_message' => $failure->getMessage(),
                ]);

                return new FetchItemRejected(ItemFailureReason::ContractRejected,
                    new ResponseAssessment(Completeness::Unavailable, AssessmentReason::SchemaMismatch, $hash));
            }
            if (! $complete || ($tab === 'common-info' && ! ($partiesComplete ?? false))) {
                CardDomHelper::logWarning('Вкладка карточки ЕИС разобрана частично (partial)', [
                    'reg_number' => $request->identity->value->value,
                    'tab' => $tab,
                    'section' => $section,
                    'complete' => $complete,
                    'count' => $count,
                ]);
            }
            $parts[] = new SnapshotPart($section, $complete ? ($count === 0 ? Completeness::CompleteEmpty : Completeness::Complete) : Completeness::Partial, true, $partHash, $fetchedAt);
        }

        return new DetailFetched(new ProcurementSnapshot($request->identity, $title, $stage, $parties, $common->items,
            $common->financingRows, $documents, $parts, $common->extension, events: $events, results: $results, initialPrice: $common->initialPrice));
    }
}
