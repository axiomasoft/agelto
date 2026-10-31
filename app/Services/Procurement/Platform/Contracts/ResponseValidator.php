<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Platform\ExpectedResource;
use App\Dto\Procurement\Platform\ResponseAssessment;

interface ResponseValidator
{
    public function assess(ResponseEnvelope $response, ExpectedResource $expected): ResponseAssessment;
}
