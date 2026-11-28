<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Services\Procurement\Acquisition\Orchestration\AcquisitionTick;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'process:schedule-acquisition')]
#[Signature('process:schedule-acquisition')]
final class ScheduleAcquisitionCommand extends Command
{
    public function handle(AcquisitionTick $producer): int
    {
        $producer->tick();

        return self::SUCCESS;
    }
}
