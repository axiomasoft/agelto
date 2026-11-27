<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Extraction;

use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\PageSourceManifest;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Pipeline\AnalysisRangeCodec;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;

/** Bounded page assignments and pending ranges, no extraction rows or raw response. */
final readonly class DiscoveryCheckpoint
{
    /**
     * @param  list<PageRange>  $pending
     * @param  array<int,int>  $assignments
     * @param  array<string,int>  $depths
     * @param  array<string,int>  $reasks
     */
    public function __construct(public array $pending, public array $assignments = [], public array $depths = [],
        public array $reasks = [], public int $splitCount = 0)
    {
        if (count($pending) > 256 || count($assignments) > 4096 || count($depths) > 511 || count($reasks) > 511
            || $splitCount < 0 || $splitCount > 255) {
            throw new \InvalidArgumentException('Discovery checkpoint exceeds its bound.');
        }
        foreach ($assignments as $page => $ordinal) {
            if ($page < 0 || $ordinal < 1 || $ordinal > 128) {
                throw new \InvalidArgumentException('Invalid source page assignment.');
            }
        }
        foreach ([$depths, $reasks] as $i => $map) {
            foreach ($map as $key => $value) {
                if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $value < 0 || $value > ($i === 0 ? 16 : 1)) {
                    throw new \InvalidArgumentException('Invalid discovery retry ordinal.');
                }
            }
        }
    }

    /** @return array<string,mixed> */
    public function fields(): array
    {
        return ['version' => 1, 'pending' => array_map(static fn (PageRange $r): array => $r->fields(), $this->pending),
            'assignments' => (object) $this->assignments, 'depths' => (object) $this->depths, 'reasks' => (object) $this->reasks, 'split_count' => $this->splitCount];
    }

    public static function restore(string $json): self
    {
        $r = PayloadReader::json($json);
        $r->keys(['version', 'pending', 'assignments', 'depths', 'reasks', 'split_count']);
        if ($r->integer('version') !== 1) {
            throw new \InvalidArgumentException('Unknown discovery codec.');
        }
        $pending = [];
        foreach ($r->list('pending') as $entry) {
            if (! $entry instanceof \stdClass || ! ($range = AnalysisRangeCodec::restore(new PayloadReader($entry))) instanceof PageRange) {
                throw new \InvalidArgumentException('Discovery requires original-page ranges.');
            }
            $pending[] = $range;
        }
        $assignments = [];
        foreach (get_object_vars($r->object('assignments')->object) as $key => $value) {
            if (! is_int($value) || ! ctype_digit((string) $key)) {
                throw new \InvalidArgumentException('Invalid discovery page assignment.');
            }
            $assignments[(int) $key] = $value;
        }
        $result = new self($pending, $assignments, self::ordinals($r->object('depths')), self::ordinals($r->object('reasks')), $r->integer('split_count'));
        if (CanonicalJson::encode($result->fields()) !== CanonicalJson::encode($r->object)) {
            throw new \InvalidArgumentException('Discovery checkpoint codec drift.');
        }

        return $result;
    }

    /** @return array<string,int> */
    private static function ordinals(PayloadReader $reader): array
    {
        $result = [];
        foreach (get_object_vars($reader->object) as $key => $value) {
            if (! is_string($key) || ! is_int($value)) {
                throw new \InvalidArgumentException('Invalid discovery retry map.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function assertPartition(PageSourceManifest $source): void
    {
        $covered = array_fill_keys(array_keys($this->assignments), true);
        foreach ($this->pending as $range) {
            for ($page = $range->start; $page < $range->end; $page++) {
                if (isset($covered[$page]) || $page >= $source->pageCount) {
                    throw new \InvalidArgumentException('Discovery page coverage overlaps or escapes source.');
                }
                $covered[$page] = true;
            }
        }
        if (count($covered) !== $source->pageCount || array_diff(array_keys($covered), range(0, $source->pageCount - 1)) !== []) {
            throw new \InvalidArgumentException('Discovery omitted original pages.');
        }
    }
}
