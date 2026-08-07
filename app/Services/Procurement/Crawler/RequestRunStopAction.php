<?php

declare(strict_types=1);

namespace App\Services\Procurement\Crawler;

use App\Enums\Procurement\Crawler\RunStopKind;
use App\Exceptions\Procurement\Crawler\RunAlreadyTerminal;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Support\Carbon;

/**
 * Пишет запрос кооперативной остановки (`stop_requested_at`/`stop_kind`/`stop_requested_by`,
 * D28), который `RunStopGate` читает изнутри процессора обхода — саму остановку прогона
 * средствами Laravel/Horizon этот класс НЕ делает (нельзя, D28): прогон завершится сам на
 * текущем блоке листинга. Идемпотентно на повторном запросе ТОГО ЖЕ вида; вид можно сменить
 * (пауза → отмена) до того, как процессор его прочитал.
 */
final readonly class RequestRunStopAction
{
    public function pause(Run $run, ?int $requestedBy): void
    {
        $this->request($run, RunStopKind::Pause, $requestedBy);
    }

    public function cancel(Run $run, ?int $requestedBy): void
    {
        $this->request($run, RunStopKind::Cancel, $requestedBy);
    }

    private function request(Run $run, RunStopKind $kind, ?int $requestedBy): void
    {
        $run->refresh();

        if ($run->status->isTerminal()) {
            throw RunAlreadyTerminal::forRun($run);
        }

        if ($run->stop_requested_at !== null && $run->stop_kind === $kind) {
            return;
        }

        $run->update([
            'stop_requested_at' => Carbon::now(),
            'stop_kind' => $kind,
            'stop_requested_by' => $requestedBy,
        ]);
    }
}
