<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Dto\Procurement\Document\Download\DownloadProgress;
use App\Dto\Procurement\Document\Download\DownloadResult;
use App\Dto\Procurement\Document\Download\SourceDocumentMetadata;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

/** Closed versioned JSON boundary; no arbitrary class hydration, unknown keys or scalar coercion. */
final readonly class DownloadStageCodec implements StagePayloadCodec
{
    public function stage(): StageCode
    {
        return StageCode::Download;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new DownloadProgress;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof DownloadProgress) {
            throw new \InvalidArgumentException('Download progress required.');
        }

        return new VersionedPayload('{}', 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        if ($this->data($payload) !== []) {
            throw new \InvalidArgumentException('Unknown download progress.');
        }

        return new DownloadProgress;
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof DownloadResult) {
            throw new \InvalidArgumentException('Download result required.');
        }
        $r = $result->artifact->reservation;

        return new VersionedPayload(json_encode([
            'document' => $result->head->documentId, 'generation' => $result->head->generation, 'version' => $result->head->versionId,
            'reservation' => $r->id->value, 'domain' => $r->domainId->value, 'token' => $r->ownerToken->value,
            'owner_generation' => $r->generation, 'ceiling' => $r->byteUpperBound, 'phase' => $r->phase->value,
            'disk' => $r->disk, 'work' => $r->workId?->value, 'attempt' => $r->attemptId?->value, 'operation' => $r->operationId?->value,
            'hash' => $result->artifact->hash->hex, 'bytes' => $result->artifact->sizeBytes,
            'filename' => $result->metadata->filename, 'mime' => $result->metadata->mime, 'etag' => $result->metadata->etag,
            'last_modified' => $result->metadata->lastModified?->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $d = $this->data($payload);
        $keys = ['document', 'generation', 'version', 'reservation', 'domain', 'token', 'owner_generation', 'ceiling', 'phase', 'disk', 'work', 'attempt', 'operation', 'hash', 'bytes', 'filename', 'mime', 'etag', 'last_modified'];
        if (count($d) !== count($keys) || array_diff($keys, array_keys($d)) !== []) {
            throw new \InvalidArgumentException('Unknown download result fields.');
        }
        $modified = $this->nullableText($d['last_modified']);
        if ($modified !== null && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $modified) !== 1) {
            throw new \InvalidArgumentException('Invalid source instant.');
        }

        return new DownloadResult(new DocumentHead($this->integer($d['document']), $this->integer($d['generation']), $d['version'] === null ? null : $this->integer($d['version'])),
            new VerifiedArtifact(new StorageReservationRequest(new ProcessUuid($this->text($d['reservation'])), new ProcessUuid($this->text($d['domain'])),
                new ProcessUuid($this->text($d['token'])), $this->integer($d['owner_generation']), $this->integer($d['ceiling']), StoragePhase::from($this->text($d['phase'])), $this->text($d['disk']),
                $this->uuid($d['work']), $this->uuid($d['attempt']), $this->uuid($d['operation'])), new ContentHash($this->text($d['hash'])), $this->integer($d['bytes'])),
            new SourceDocumentMetadata($this->text($d['filename']), $this->text($d['mime']), $this->nullableText($d['etag']), $modified === null ? null : new \DateTimeImmutable($modified)));
    }

    /** @return array<string,mixed> */
    private function data(VersionedPayload $p): array
    {
        if ($p->schemaVersion !== 1 || $p->json === null || strlen($p->json) > 8192 || ! str_starts_with($p->json, '{')) {
            throw new \InvalidArgumentException('Unsupported download schema.');
        }
        $d = json_decode($p->json, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($d)) {
            throw new \InvalidArgumentException('Download object required.');
        }

        $object = [];
        foreach ($d as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Object fields required.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    private function integer(mixed $v): int
    {
        return is_int($v) ? $v : throw new \InvalidArgumentException('Integer required.');
    }

    private function text(mixed $v): string
    {
        return is_string($v) ? $v : throw new \InvalidArgumentException('Text required.');
    }

    private function nullableText(mixed $v): ?string
    {
        return $v === null ? null : $this->text($v);
    }

    private function uuid(mixed $v): ?ProcessUuid
    {
        return $v === null ? null : new ProcessUuid($this->text($v));
    }
}
