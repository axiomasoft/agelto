<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalJson;

final readonly class AnalysisWindowSnapshot
{
    public ContentHash $inputHash;

    /** @param array<string, int> $sourceAnchors */
    public function __construct(
        public ProcessUuid $id,
        public AnalysisRunSnapshot $run,
        public string $windowKey,
        public RowRange|PageRange $range,
        public int $estimateOrdinal,
        public array $sourceAnchors,
    ) {
        if ($windowKey === '' || strlen($windowKey) > 256 || $estimateOrdinal < 1 || $sourceAnchors === [] || count($sourceAnchors) > 4096) {
            throw new \InvalidArgumentException('Invalid analysis window identity/coverage.');
        }
        new EstimateOrdinal($estimateOrdinal);
        if ($range instanceof PageRange && ($run->source === null || $run->source->representationHash != $run->representationHash
            || $sourceAnchors != $run->source->anchorsFor($range))) {
            throw new \InvalidArgumentException('Window must use the independently sealed original-page anchors.');
        }
        if ($range instanceof RowRange && $run->source !== null) {
            throw new \InvalidArgumentException('Row and page coverage cannot share a run.');
        }
        foreach ($sourceAnchors as $anchor => $page) {
            if ($anchor === '' || $page < 0) {
                throw new \InvalidArgumentException('Invalid source anchor.');
            }
        }
        $this->inputHash = new ContentHash(CanonicalJson::hash(['version' => 1, 'window_id' => $id->value,
            'window_key' => $windowKey, 'run_id' => $run->id->value, 'recipe_hash' => $run->recipe->hash->hex,
            'representation_hash' => $run->representationHash->hex, 'range' => $range->fields(),
            'estimate_ordinal' => $estimateOrdinal, 'anchors' => $sourceAnchors]));
    }

    public function subrangeKey(RowRange|PageRange $range): ContentHash
    {
        if ($range::class !== $this->range::class || $range->start < $this->range->start || $range->end > $this->range->end) {
            throw new \InvalidArgumentException('Subrange escapes parent.');
        }

        return new ContentHash(CanonicalJson::hash(['version' => 1, 'parent_window_id' => $this->id->value,
            'range' => $range->fields(), 'representation_hash' => $this->run->representationHash->hex]));
    }

    public function subrangeInputHash(RowRange|PageRange $range, TaskPurpose $purpose = TaskPurpose::Rows): ContentHash
    {
        return new ContentHash(CanonicalJson::hash(['version' => 2, 'task_purpose' => $purpose->value, 'window_input_hash' => $this->inputHash->hex,
            'range' => $range->fields(), 'subrange_key' => $this->subrangeKey($range)->hex]));
    }
}
