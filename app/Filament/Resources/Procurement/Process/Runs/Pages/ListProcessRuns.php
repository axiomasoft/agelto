<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Runs\Pages;

use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use Filament\Resources\Pages\ListRecords;

final class ListProcessRuns extends ListRecords
{
    protected static string $resource = ProcessRunResource::class;
}
