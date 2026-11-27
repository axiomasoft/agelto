<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Extraction;

use App\Dto\Procurement\Ai\Planning\ChunkReference;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Pipeline\AnalysisRangeCodec;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;

/** Small durable references only; rows live in immutable chunks, never in a checkpoint. */
final readonly class ExtractionCheckpoint
{
    /**
     * @param  list<RowRange|PageRange>  $pending
     * @param  list<ChunkReference>  $acceptedChunks
     */
    public function __construct(public array $pending, public array $acceptedChunks, public int $splitCount = 0)
    {
        if ($splitCount < 0 || $splitCount > 255 || count($pending) > 256 || count($acceptedChunks) > 4096) {
            throw new \InvalidArgumentException('Checkpoint limit.');
        }
        $end = -1;
        foreach ($pending as $range) {
            if ($range->start < $end) {
                throw new \InvalidArgumentException('Pending ranges must be ordered and disjoint.');
            }
            $end = $range->end;
        }
    }

    public function json(): string
    {
        return CanonicalJson::encode(['version' => 1, 'pending' => array_map(fn (RowRange|PageRange $range): array => $range->fields(), $this->pending),
            'accepted_chunks' => array_map(fn (ChunkReference $ref): array => ['id' => $ref->id->value, 'output_hash' => $ref->outputHash->hex], $this->acceptedChunks), 'split_count' => $this->splitCount]);
    }

    public static function restore(string $json): self
    {
        $reader = PayloadReader::json($json);
        $reader->keys(['version', 'pending', 'accepted_chunks', 'split_count']);
        if ($reader->integer('version') !== 1) {
            throw new \InvalidArgumentException('Unknown checkpoint codec; cancel and create a new analysis.');
        }
        $pending = $reader->object->pending;
        $chunks = $reader->object->accepted_chunks;
        if (! is_array($pending) || ! is_array($chunks)) {
            throw new \InvalidArgumentException('Invalid checkpoint collections.');
        }
        $ranges = [];
        foreach ($pending as $value) {
            if (! $value instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid range.');
            }
            $range = new PayloadReader($value);
            $ranges[] = AnalysisRangeCodec::restore($range);
        }
        $hashes = [];
        foreach ($chunks as $value) {
            if (! $value instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid chunk reference.');
            }
            $ref = new PayloadReader($value);
            $ref->keys(['id', 'output_hash']);
            $hashes[] = new ChunkReference(new ProcessUuid($ref->string('id')), new ContentHash($ref->string('output_hash')));
        }

        return new self($ranges, $hashes, $reader->integer('split_count'));
    }
}
