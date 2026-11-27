<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Network\Http\ResponseEnvelope;
use App\Dto\Procurement\Platform\Diagnostics\ExpectedResource;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;

interface ResponseValidator
{
    public function assess(ResponseEnvelope $response, ExpectedResource $expected): ResponseAssessment;
}
