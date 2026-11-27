<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Dto\Procurement\Document\Prepare\PdfBatchText;
use App\Dto\Procurement\Document\Prepare\PrepareRange;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use Illuminate\Container\Attributes\Bind;

#[Bind(PageBatchOcr::class)]
interface PdfPreparationTools
{
    public function pageCount(string $source, string $directory, ExecutionContext $execution, float $deadline): int;

    public function batch(string $source, string $directory, PrepareRange $range, ExecutionContext $execution, float $deadline): PdfBatchText;
}
