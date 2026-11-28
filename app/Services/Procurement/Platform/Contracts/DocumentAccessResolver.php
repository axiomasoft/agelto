<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\Fetch\FetchBlocked;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Acquisition\Fetch\FetchItemRejected;
use App\Dto\Procurement\Document\Download\DocumentFetchPlan;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\DownloadInput;

/** Adapter seam: refresh=true is allowed once per logical operation after an expired signed URL. */
interface DocumentAccessResolver
{
    public function resolve(DownloadInput $input, ExecutionContext $execution, bool $refresh = false): DocumentFetchPlan|FetchDeferred|FetchBlocked|FetchItemRejected;
}
