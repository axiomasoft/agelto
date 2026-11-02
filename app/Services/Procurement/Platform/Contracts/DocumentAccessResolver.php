<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Document\DocumentFetchPlan;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExecutionContext;

/** Adapter seam: refresh=true is allowed once per logical operation after an expired signed URL. */
interface DocumentAccessResolver
{
    public function resolve(DownloadInput $input, ExecutionContext $execution, bool $refresh = false): DocumentFetchPlan|FetchDeferred|FetchBlocked|FetchItemRejected;
}
