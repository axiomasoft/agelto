<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Panel;

use App\Models\Procurement\Proxyma\NetworkAuthority;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;

/** Selector блокируется раньше catalog locks, переключение ждёт завершения редактора. */
final class UiAuthority implements CatalogMutationFence
{
    public function version(): ?string
    {
        $row = DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->first(['authority', 'generation', 'paused']);

        return $row === null || $row->paused || ! is_string($row->authority) || ! is_int($row->generation)
            ? null : $row->authority.':'.$row->generation;
    }

    public function active(string $engine): bool
    {
        return str_starts_with($this->version() ?? '', $engine.':');
    }

    public function lock(Connection $connection, CatalogMutationActor $actor): void
    {
        $this->assertLocked($connection, 'proxyma', $actor->authorityVersion);
    }

    public function assertLocked(Connection $connection, string $engine, ?string $expected): void
    {
        if ($connection !== DB::connection() || $connection->transactionLevel() < 1) {
            throw new \LogicException('UI authority requires the owning connection transaction.');
        }
        $row = $connection->table((new NetworkAuthority)->getTable())->where('id', 'network')->sharedLock()->first(['authority', 'generation', 'paused']);
        if ($row === null || $row->paused || ! is_string($row->authority) || ! is_int($row->generation)
            || $row->authority !== $engine || $expected !== $row->authority.':'.$row->generation) {
            throw new CatalogConflict('Network authority generation changed. Reload the form.');
        }
    }
}
