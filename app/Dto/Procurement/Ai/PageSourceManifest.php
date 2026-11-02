<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\PayloadReader;

/** Independently prepared original pages and anchors. No model result or raw text. */
final readonly class PageSourceManifest
{
    /**
     * @param  array<string,int>  $anchors
     * @param  list<int>  $blankPages
     */
    public function __construct(public ContentHash $representationHash, public int $pageCount,
        public array $anchors, public array $blankPages)
    {
        if ($pageCount < 1 || $pageCount > 4096 || count($anchors) > 65536
            || count(array_unique($blankPages)) !== count($blankPages)) {
            throw new \InvalidArgumentException('Invalid bounded source page manifest.');
        }
        foreach ($anchors as $anchor => $page) {
            if ($page < 0 || $page >= $pageCount
                || preg_match('/^page:'.$page.'(?::line:[0-9]+)?$/D', $anchor) !== 1) {
                throw new \InvalidArgumentException('Source anchor escapes original pages.');
            }
        }
        for ($page = 0; $page < $pageCount; $page++) {
            if (($anchors['page:'.$page] ?? null) !== $page) {
                throw new \InvalidArgumentException('Source manifest omits an original page.');
            }
        }
        foreach ($blankPages as $page) {
            if ($page < 0 || $page >= $pageCount) {
                throw new \InvalidArgumentException('Blank proof escapes source.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function fields(): array
    {
        return ['version' => 1, 'representation_hash' => $this->representationHash->hex, 'page_count' => $this->pageCount,
            'anchors' => (object) $this->anchors, 'blank_pages' => $this->blankPages];
    }

    public static function restore(string $json): self
    {
        $r = PayloadReader::json($json);
        $r->keys(['version', 'representation_hash', 'page_count', 'anchors', 'blank_pages']);
        if ($r->integer('version') !== 1) {
            throw new \InvalidArgumentException('Unknown source page codec.');
        }
        $anchors = [];
        foreach (get_object_vars($r->object('anchors')->object) as $key => $value) {
            if (! is_string($key) || ! is_int($value)) {
                throw new \InvalidArgumentException('Invalid source anchor codec.');
            }
            $anchors[$key] = $value;
        }
        $blank = [];
        foreach ($r->list('blank_pages') as $page) {
            if (! is_int($page)) {
                throw new \InvalidArgumentException('Invalid blank-page proof.');
            }
            $blank[] = $page;
        }
        $manifest = new self(new ContentHash($r->string('representation_hash')), $r->integer('page_count'), $anchors, $blank);
        if (CanonicalJson::encode($manifest->fields()) !== CanonicalJson::encode($r->object)) {
            throw new \InvalidArgumentException('Source manifest codec drift.');
        }

        return $manifest;
    }

    /** @return array<string,int> */
    public function anchorsFor(PageRange $range): array
    {
        if ($range->end > $this->pageCount) {
            throw new \InvalidArgumentException('Page range escapes source.');
        }

        return array_filter($this->anchors, static fn (int $page): bool => $page >= $range->start && $page < $range->end);
    }
}
