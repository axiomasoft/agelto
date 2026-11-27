<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Procurement\Proxyma\Migration\Cutover;
use App\Services\Procurement\Proxyma\Migration\CutoverJournal;
use App\Services\Procurement\Proxyma\Migration\Inventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'proxyma:cutover')]
final class ProxymaCutoverCommand extends Command
{
    protected $signature = 'proxyma:cutover {action : inventory|run|resume|rollback} {id}
        {--generation= : Expected selector generation} {--wait-ms=1000 : Bounded drain horizon, 0..60000}
        {--map= : Private JSON endpoint mapping file} {--key-file= : Private HMAC key file}';

    protected $description = 'Drained single-authority cutover; rollback only before the first durable claim';

    public function handle(): int
    {
        // Files are read before entering SQL; no credentials or source rows are printed.
        try {
            $key = file_get_contents((string) $this->option('key-file'));
            if ($key === false) {
                throw new \DomainException;
            }
            $inventory = new Inventory(DB::connection(), $key);
            if ($this->argument('action') === 'inventory') {
                $this->line(json_encode($inventory->dryRun(), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $generation = filter_var($this->option('generation'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $wait = filter_var($this->option('wait-ms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60000]]);
            if ($generation === false || $wait === false) {
                throw new \DomainException;
            }
            $cutover = new Cutover(new CutoverJournal($inventory));
            $id = (string) $this->argument('id');
            $result = match ($this->argument('action')) {
                'run' => $cutover->run($id, $this->endpointMap(), $generation, $wait),
                'resume' => $cutover->resume($id, $generation),
                'rollback' => $cutover->rollback($id, $generation, $wait),
                default => throw new \DomainException,
            };
            $this->line($result);

            return in_array($result, ['accepted', 'rolled_back'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Cutover stopped; inspect the persisted journal and selector. Do not resume stale workers.');

            return self::FAILURE;
        }
    }

    /** @return array<int, array{endpoint_id: string, protocol: string}> */
    private function endpointMap(): array
    {
        $json = file_get_contents((string) $this->option('map'));
        if ($json === false) {
            throw new \DomainException('Endpoint mapping file is unreadable.');
        }
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new \DomainException('Endpoint mapping must be an object keyed by proxy ID.');
        }
        $map = [];
        foreach ($value as $id => $entry) {
            if (! is_int($id) || $id < 1 || ! is_array($entry)
                || ! is_string($entry['endpoint_id'] ?? null) || ! is_string($entry['protocol'] ?? null)) {
                throw new \DomainException('Invalid endpoint mapping.');
            }
            $map[$id] = ['endpoint_id' => $entry['endpoint_id'], 'protocol' => $entry['protocol']];
        }

        return $map;
    }
}
