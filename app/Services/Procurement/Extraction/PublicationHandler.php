<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Services\Procurement\Process\Contracts\StageHandler;

final readonly class PublicationHandler implements StageHandler
{
    public function __construct(private EstimatePublisher $publisher) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof PublicationInput) {
            throw new \InvalidArgumentException('Publication input required.');
        }
        $execution->tick();
        try {
            return new StageSucceeded($this->publisher->result($input));
        } catch (PublicationBlocked $blocked) {
            return new StageBlocked($blocked->reason);
        }
    }
}
