<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Planning;

/** Original zero-based pages, half-open. Never byte offsets of a PDF/UTF-8 file. */
final readonly class PageRange
{
    public function __construct(public int $start, public int $end)
    {
        new RowRange($start, $end);
    }

    /** @return array{start:int,end:int,unit:string} */
    public function fields(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'unit' => 'page'];
    }

    /** @return array{self,self} */
    public function split(): array
    {
        if ($this->end - $this->start < 2) {
            throw new \InvalidArgumentException('An original page is indivisible.');
        }
        $mid = $this->start + intdiv($this->end - $this->start, 2);

        return [new self($this->start, $mid), new self($mid, $this->end)];
    }
}
