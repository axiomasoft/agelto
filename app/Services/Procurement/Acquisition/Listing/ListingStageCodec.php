<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummaries;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Acquisition\Listing\ListingCheckpoint;
use App\Dto\Procurement\Acquisition\Listing\ListingFacts;
use App\Dto\Procurement\Acquisition\Listing\ListingPage;
use App\Dto\Procurement\Acquisition\Listing\ListingResult;
use App\Dto\Procurement\Acquisition\Listing\ListingTask;
use App\Dto\Procurement\Acquisition\Listing\PartitionResult;
use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Acquisition\Detail\DetailValueCodec;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

final readonly class ListingStageCodec implements StagePayloadCodec
{
    public function __construct(private DetailValueCodec $values = new DetailValueCodec) {}

    public function stage(): StageCode
    {
        return StageCode::Listing;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new ListingCheckpoint;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof ListingCheckpoint) {
            throw new \InvalidArgumentException('Listing checkpoint required.');
        }

        return new VersionedPayload('{}', 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        $this->reader($payload)->keys([]);

        return new ListingCheckpoint;
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof ListingResult) {
            throw new \InvalidArgumentException('Listing result required.');
        }
        $page = $result->page;
        $summaries = [];
        foreach ($page->summaries->items as $s) {
            $summaries[] = ['platform' => $s->identity->platform->value, 'type' => $s->identity->type->value,
                'value' => $s->identity->value->value, 'title' => $s->title, 'stage' => $s->sourceStage,
                'published' => $s->publishedAt->format(DATE_ATOM), 'updated' => $s->updatedAt->format(DATE_ATOM),
                'updatedBefore' => $s->updatedBefore?->format(DATE_ATOM),
                'fingerprint' => $s->fingerprint->hex, 'revision' => $s->sourceRevision, 'price' => $s->initialPrice,
                'precision' => $s->updatedAtDayPrecision ? 1 : 0, 'currency' => $s->currency, 'locator' => $s->detailLocator === null ? null : ListingTask::envelope($s->detailLocator),
                'facts' => $s->facts === null ? null : $this->values->encode($s->facts)];
        }

        return new VersionedPayload(json_encode(['observedAt' => $result->observedAt->format('Y-m-d\TH:i:s.uP'), 'task' => $result->task->json(), 'summaries' => $summaries,
            'rows' => $page->rowsTotal, 'completeness' => $page->assessment->completeness->value, 'reason' => $page->assessment->reason->value,
            'bodyHash' => $page->assessment->bodyHash->hex, 'confidence' => $page->assessment->confidence,
            'artifact' => $page->assessment->artifact === null ? null : ['disk' => $page->assessment->artifact->disk,
                'key' => $page->assessment->artifact->key, 'hash' => $page->assessment->artifact->sha256->hex],
            'terminal' => $page->terminalProof->value,
            'next' => $page->nextCursor === null ? null : ListingTask::envelope($page->nextCursor),
            'split' => $result->split === null ? null : ['children' => array_map(ListingTask::envelope(...), $result->split->children),
                'reason' => $result->split->incompleteReason?->value]], JSON_THROW_ON_ERROR), 3);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $r = $this->reader($payload, 3);
        $r->keys(['observedAt', 'task', 'summaries', 'rows', 'completeness', 'reason', 'bodyHash', 'confidence', 'artifact', 'terminal', 'next', 'split']);
        $items = [];
        foreach ($this->list($r, 'summaries', 1000) as $value) {
            if (! $value instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid summary.');
            }
            $s = new PayloadReader($value);
            $s->keys(['platform', 'type', 'value', 'title', 'stage', 'published', 'updated', 'updatedBefore', 'fingerprint', 'revision', 'price', 'currency', 'locator', 'precision', 'facts']);
            $facts = $s->isNull('facts') ? null : $this->values->decode($s->string('facts'));
            if ($facts !== null && ! $facts instanceof ListingFacts) {
                throw new \InvalidArgumentException('Invalid listing facts.');
            }
            $items[] = new ProcurementSummary(new SourceIdentity(new PlatformCode($s->string('platform')),
                new SourceIdentifierType($s->string('type')), new SourceIdentifierValue($s->string('value'))),
                $s->string('title'), $s->string('stage'), new \DateTimeImmutable($s->string('published')), new \DateTimeImmutable($s->string('updated')),
                new ContentHash($s->string('fingerprint')), $s->isNull('revision') ? null : $s->string('revision'),
                $s->isNull('price') ? null : $s->string('price'), $s->isNull('currency') ? null : $s->string('currency'),
                $s->isNull('locator') ? null : $this->cursor($s->object('locator')), $this->precision($s), $facts,
                $s->isNull('updatedBefore') ? null : new \DateTimeImmutable($s->string('updatedBefore')));
        }
        $split = null;
        if (! $r->isNull('split')) {
            $s = $r->object('split');
            $s->keys(['children', 'reason']);
            $children = [];
            foreach ($this->list($s, 'children', 2) as $value) {
                if (! $value instanceof \stdClass) {
                    throw new \InvalidArgumentException('Invalid split.');
                }
                $children[] = $this->cursor(new PayloadReader($value));
            }
            $split = new PartitionResult($children, $s->isNull('reason') ? null : AssessmentReason::from($s->string('reason')));
        }

        $confidence = $r->object->confidence;
        if (! is_int($confidence) && ! is_float($confidence)) {
            throw new \InvalidArgumentException('Invalid assessment confidence.');
        }
        $artifact = null;
        if (! $r->isNull('artifact')) {
            $a = $r->object('artifact');
            $a->keys(['disk', 'key', 'hash']);
            $artifact = new ArtifactRef($a->string('disk'), $a->string('key'), new ContentHash($a->string('hash')));
        }

        return new ListingResult(ListingTask::fromJson($r->string('task')), new ListingPage(new ProcurementSummaries($items), $r->integer('rows'),
            new ResponseAssessment(Completeness::from($r->string('completeness')), AssessmentReason::from($r->string('reason')), new ContentHash($r->string('bodyHash')), (float) $confidence, $artifact),
            TerminalProof::from($r->string('terminal')), $r->isNull('next') ? null : $this->cursor($r->object('next'))), $split, new \DateTimeImmutable($r->string('observedAt')));
    }

    private function precision(PayloadReader $reader): bool
    {
        $value = $reader->integer('precision');
        if ($value !== 0 && $value !== 1) {
            throw new \InvalidArgumentException('Invalid date precision.');
        }

        return $value === 1;
    }

    private function reader(VersionedPayload $payload, int $version = 1): PayloadReader
    {
        if ($payload->schemaVersion !== $version || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported listing schema.');
        }

        return PayloadReader::json($payload->json);
    }

    private function cursor(PayloadReader $r): CursorEnvelope
    {
        $r->keys(['codec', 'version', 'payload']);

        return new CursorEnvelope($r->string('codec'), $r->integer('version'), $r->string('payload'));
    }

    /** @return list<mixed> */
    private function list(PayloadReader $r, string $key, int $max): array
    {
        $value = $r->object->{$key} ?? null;
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            throw new \InvalidArgumentException('Invalid bounded list.');
        }

        return $value;
    }
}
