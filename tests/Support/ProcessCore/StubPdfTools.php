<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Document\Prepare\PdfBatchText;
use App\Dto\Procurement\Document\Prepare\PreparePage;
use App\Dto\Procurement\Document\Prepare\PrepareRange;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Services\Procurement\Prepare\Tools\PdfPreparationTools;

final class StubPdfTools implements PdfPreparationTools
{
    /** @var list<array{int,int}> */
    public array $calls = [];

    /** @param list<int> $unrecognized */
    public function __construct(public int $count = 46, public ?int $failingPage = null, public array $unrecognized = [], public ?\Closure $afterSetup = null) {}

    public function pageCount(string $source, string $directory, ExecutionContext $execution, float $deadline): int
    {
        ($this->afterSetup ?? static fn () => null)();

        return $this->count;
    }

    public function batch(string $source, string $directory, PrepareRange $range, ExecutionContext $execution, float $deadline): PdfBatchText
    {
        $this->calls[] = [$range->start, $range->end];
        if ($this->failingPage !== null && $this->failingPage > $range->start && $this->failingPage <= $range->end) {
            throw new ToolFailed('page_timeout');
        }
        $pages = [];
        $text = '';
        for ($p = $range->start + 1; $p <= $range->end; $p++) {
            $pages[] = new PreparePage($p, in_array($p, $this->unrecognized, true) ? PageTextStatus::Unrecognized : ($p % 2 === 1 ? PageTextStatus::NativeText : PageTextStatus::OcrText));
            $text .= '[page:'.$p."]\ncontent ".$p."\n";
        }

        return new PdfBatchText($text, $pages, 1);
    }
}
