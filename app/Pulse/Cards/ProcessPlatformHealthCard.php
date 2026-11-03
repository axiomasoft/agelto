<?php

declare(strict_types=1);

namespace App\Pulse\Cards;

use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/** Measurements and coverage proof, not an inferred platform-wide health flag. */
#[Lazy]
final class ProcessPlatformHealthCard extends Card
{
    public function render(): View
    {
        $snapshots = [];
        foreach (app(PlatformRegistry::class)->all() as $definition) {
            $snapshots[] = app(PlatformMetricsReadRepository::class)->read($definition->code);
        }

        return view('livewire.pulse.process-platform-health', ['snapshots' => $snapshots]);
    }
}
