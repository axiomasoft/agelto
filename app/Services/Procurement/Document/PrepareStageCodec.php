<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\PreparedBatch;
use App\Dto\Procurement\Document\PreparedDocument;
use App\Dto\Procurement\Document\PrepareOcrCheckpoint;
use App\Dto\Procurement\Document\PreparePage;
use App\Dto\Procurement\Document\PrepareRange;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\PayloadReader;
use App\Services\Procurement\Process\StagePayloadCodec;

/** Closed schema, explicit scalar types, bounded lists. No reflection or arbitrary hydration. */
final readonly class PrepareStageCodec implements StagePayloadCodec
{
    public function stage(): StageCode
    {
        return StageCode::Prepare;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new PrepareOcrCheckpoint;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof PrepareOcrCheckpoint) {
            throw new \InvalidArgumentException('Prepare checkpoint required.');
        }

        return $this->encode($this->progress($checkpoint));
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        return $this->checkpoint($this->reader($payload));
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof PreparedDocument) {
            throw new \InvalidArgumentException('Prepared result required.');
        }

        return $this->encode(['head' => [$result->head->documentId, $result->head->generation, $result->head->versionId],
            'manifest' => $this->progress($result->manifest), 'text' => $this->artifact($result->text)]);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $r = $this->reader($payload);
        $r->keys(['head', 'manifest', 'text']);
        $h = $r->object->head;
        if (! is_array($h) || count($h) !== 3 || ! array_is_list($h) || ! is_int($h[0]) || ! is_int($h[1]) || ! is_int($h[2])) {
            throw new \InvalidArgumentException('Invalid document head.');
        }

        return new PreparedDocument(new DocumentHead($h[0], $h[1], $h[2]), $this->checkpoint($r->object('manifest')), $this->readArtifact($r->object('text')));
    }

    /** @return array<string,mixed> */
    private function progress(PrepareOcrCheckpoint $c): array
    {
        return ['source' => $c->sourceHash?->hex, 'recipe' => $c->recipeHash?->hex, 'pages' => $c->originalPages, 'limit' => $c->pageLimit, 'next' => $c->nextBatchSize,
            'pending' => array_map(fn (PrepareRange $r): array => [$r->start, $r->end, $r->failures], $c->pending),
            'batches' => array_map(fn (PreparedBatch $b): array => ['artifact' => $this->artifact($b->artifact), 'pages' => $this->pages($b->pages)], $c->batches),
            'failed' => $this->pages($c->failed)];
    }

    private function checkpoint(PayloadReader $r): PrepareOcrCheckpoint
    {
        $r->keys(['source', 'recipe', 'pages', 'limit', 'next', 'pending', 'batches', 'failed']);
        $pending = [];
        foreach ($this->list($r, 'pending') as $range) {
            if (! is_array($range) || count($range) !== 3 || ! array_is_list($range) || ! is_int($range[0]) || ! is_int($range[1]) || ! is_int($range[2])) {
                throw new \InvalidArgumentException('Invalid range.');
            }
            $pending[] = new PrepareRange($range[0], $range[1], $range[2]);
        }
        $batches = [];
        foreach ($this->list($r, 'batches') as $b) {
            if (! $b instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid batch.');
            }
            $br = new PayloadReader($b);
            $br->keys(['artifact', 'pages']);
            $pages = $this->readPages($br, 'pages');
            if ($pages === []) {
                throw new \InvalidArgumentException('Batch pages required.');
            }
            $batches[] = new PreparedBatch($this->readArtifact($br->object('artifact')), $pages);
        }

        return new PrepareOcrCheckpoint($r->isNull('source') ? null : new ContentHash($r->string('source')),
            $r->isNull('recipe') ? null : new ContentHash($r->string('recipe')), $r->integer('pages'), $r->integer('limit'),
            $pending, $batches, $this->readPages($r, 'failed'), $r->integer('next'));
    }

    /**
     * @param  list<PreparePage>  $pages
     * @return list<array{int,string,?string}>
     */
    private function pages(array $pages): array
    {
        return array_map(fn (PreparePage $p): array => [$p->page, $p->status->value, $p->evidence], $pages);
    }

    /** @return list<PreparePage> */
    private function readPages(PayloadReader $r, string $key): array
    {
        $result = [];
        foreach ($this->list($r, $key) as $p) {
            if (! is_array($p) || count($p) !== 3 || ! array_is_list($p) || ! is_int($p[0]) || ! is_string($p[1]) || (! is_string($p[2]) && $p[2] !== null)) {
                throw new \InvalidArgumentException('Invalid page status.');
            }
            $result[] = new PreparePage($p[0], PageTextStatus::from($p[1]), $p[2]);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function artifact(VerifiedArtifact $a): array
    {
        $r = $a->reservation;

        return ['id' => $r->id->value, 'domain' => $r->domainId->value, 'token' => $r->ownerToken->value, 'generation' => $r->generation,
            'ceiling' => $r->byteUpperBound, 'disk' => $r->disk, 'work' => $r->workId?->value, 'attempt' => $r->attemptId?->value,
            'hash' => $a->hash->hex, 'bytes' => $a->sizeBytes];
    }

    private function readArtifact(PayloadReader $r): VerifiedArtifact
    {
        $r->keys(['id', 'domain', 'token', 'generation', 'ceiling', 'disk', 'work', 'attempt', 'hash', 'bytes']);

        return new VerifiedArtifact(new StorageReservationRequest(new ProcessUuid($r->string('id')), new ProcessUuid($r->string('domain')),
            new ProcessUuid($r->string('token')), $r->integer('generation'), $r->integer('ceiling'), StoragePhase::Prepare, $r->string('disk'),
            $r->isNull('work') ? null : new ProcessUuid($r->string('work')), $r->isNull('attempt') ? null : new ProcessUuid($r->string('attempt'))),
            new ContentHash($r->string('hash')), $r->integer('bytes'));
    }

    /** @return list<mixed> */
    private function list(PayloadReader $r, string $key): array
    {
        $v = get_object_vars($r->object)[$key] ?? null;
        if (! is_array($v) || ! array_is_list($v) || count($v) > 10000) {
            throw new \InvalidArgumentException('Invalid bounded list.');
        }

        return $v;
    }

    /** @param array<string,mixed> $d */
    private function encode(array $d): VersionedPayload
    {
        return new VersionedPayload(json_encode($d, JSON_THROW_ON_ERROR), 1);
    }

    private function reader(VersionedPayload $p): PayloadReader
    {
        if ($p->schemaVersion !== 1 || $p->json === null || strlen($p->json) > 2097152) {
            throw new \InvalidArgumentException('Unsupported preparation schema.');
        }

        return PayloadReader::json($p->json);
    }
}
