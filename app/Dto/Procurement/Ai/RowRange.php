<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

final readonly class RowRange
{
    public function __construct(public int $start, public int $end)
    {
        if ($start < 0 || $end <= $start) {
            throw new \InvalidArgumentException('Expected a nonempty half-open row range.');
        }
    }

    /** @return array{start:int,end:int} */
    public function fields(): array
    {
        return ['start' => $this->start, 'end' => $this->end];
    }

    /** @return array{self,self} */
    public function split(): array
    {
        if ($this->end - $this->start < 2) {
            throw new \InvalidArgumentException('Indivisible range.');
        }
        $mid = $this->start + intdiv($this->end - $this->start, 2);

        return [new self($this->start, $mid), new self($mid, $this->end)];
    }
}
