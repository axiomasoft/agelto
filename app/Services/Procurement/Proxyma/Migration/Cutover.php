<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use App\Models\Procurement\Proxyma\CutoverJournal as Journal;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\TransferRecord;
use App\Support\Database\TransactionGuard;
use Closure;
use DomainException;
use Illuminate\Database\Query\Builder;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\PackageSchema;

/**
 * SQL-only transitions; bounded waiting and phase observers run outside transactions.
 *
 * @phpstan-type Selector object{authority: string, generation: int, cutover_id: string|null, paused: bool, legacy_frozen: bool}
 * @phpstan-type JournalRow object{phase: string, generation: int, checkpoint: string, first_authorized_at: string|null, source_revision: string, target_revision: string}
 */
final readonly class Cutover
{
    public function __construct(private CutoverJournal $journal, private ?Closure $afterPhase = null) {}

    /** @param array<int, array{endpoint_id: string, protocol: string}> $endpoints */
    public function run(string $id, array $endpoints, int $expectedGeneration, int $waitMs = 1000): string
    {
        TransactionGuard::forbidActive('Cutover durable phase commits and waits', $this->journal->inventory->db);
        new Uuid($id);
        $this->bounds($waitMs);
        $row = $this->row($id);
        if ($row !== null && in_array($row->phase, ['switched', 'accepted'], true)) {
            return $this->resume($id, $expectedGeneration);
        }
        if ($row?->phase === 'verified') {
            return $this->switch($id, $expectedGeneration);
        }
        $this->locked(function ($selector) use ($id, $expectedGeneration): void {
            $this->expect($selector, 'legacy', $expectedGeneration);
            $report = $this->journal->inventory->dryRun();
            if ($this->row($id) === null) {
                $this->journals()->insert(['id' => $id, 'source_revision' => $report['source_revision'],
                    'target_revision' => $report['target_revision'], 'generation' => $expectedGeneration + 1,
                    'phase' => 'prepared', 'checkpoint' => '{}']);
            }
        });
        $this->observed('prepared');
        new TransferCatalog($this->journal)->run($id, $endpoints);
        $this->locked(function ($selector) use ($id, $expectedGeneration): void {
            $this->expect($selector, 'legacy', $expectedGeneration);
            if ($selector->cutover_id !== null && $selector->cutover_id !== $id) {
                throw new DomainException('Another cutover owns the pause.');
            }
            $this->selector()->update(['paused' => true, 'cutover_id' => $id]);
        });
        $this->observed('draining');
        if (! $this->drain($waitMs, false)) {
            $this->recordDrainHold($id, $expectedGeneration);

            return 'drain_timeout_held';
        }
        new TransferState($this->journal)->run($id);
        $this->observed('transferred');
        $this->locked(function ($selector) use ($id, $expectedGeneration): void {
            $this->expect($selector, 'legacy', $expectedGeneration, $id);
            if (! $selector->paused) {
                throw new DomainException('Issuance must be paused.');
            }
            $this->verify($id);
            $checkpoint = Inventory::checkpoint($this->requiredRow($id)->checkpoint);
            $checkpoint['rollback_target_checksum'] = $this->targetChecksum();
            $this->selector()->update(['legacy_frozen' => true]);
            $this->journals()->where('id', $id)->update(['phase' => 'verified', 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
        });
        $this->observed('verified');

        return $this->switch($id, $expectedGeneration);
    }

    private function switch(string $id, int $expectedGeneration): string
    {
        $this->locked(function ($selector) use ($id, $expectedGeneration): void {
            $this->expect($selector, 'legacy', $expectedGeneration, $id);
            if (! $selector->paused || ! $selector->legacy_frozen || $this->requiredRow($id)->phase !== 'verified'
                || $this->requiredRow($id)->generation !== $expectedGeneration + 1) {
                throw new DomainException('Fresh verification and writer freeze required.');
            }
            $this->verify($id);
            $generation = $expectedGeneration + 1;
            $this->selector()->update(['authority' => 'proxyma', 'generation' => $generation]);
            $this->journals()->where('id', $id)->update(['phase' => 'switched']);
        });
        $this->observed('switched');

        return $this->resume($id, $expectedGeneration + 1);
    }

    public function resume(string $id, int $expectedGeneration): string
    {
        TransactionGuard::forbidActive('Cutover durable phase commits and waits', $this->journal->inventory->db);
        $this->locked(function ($selector) use ($id, $expectedGeneration): void {
            $this->expect($selector, 'proxyma', $expectedGeneration, $id);
            $row = $this->requiredRow($id);
            $checkpoint = Inventory::checkpoint($row->checkpoint);
            if ($row->generation !== $expectedGeneration || ! in_array($row->phase, ['switched', 'accepted'], true) || isset($checkpoint['rollback_refused'])) {
                throw new DomainException('Held cutover requires a roll-forward fix.');
            }
            $this->selector()->update(['paused' => false]);
            $this->journals()->where('id', $id)->update(['phase' => 'accepted']);
        });
        $this->observed('accepted');

        return 'accepted';
    }

    public function rollback(string $id, int $expectedGeneration, int $waitMs = 1000): string
    {
        TransactionGuard::forbidActive('Cutover durable phase commits and waits', $this->journal->inventory->db);
        $this->bounds($waitMs);
        $refused = $this->locked(function ($selector) use ($id, $expectedGeneration): bool {
            $this->expect($selector, 'proxyma', $expectedGeneration, $id);
            $this->selector()->update(['paused' => true]);
            $row = $this->requiredRow($id);
            if ($row->first_authorized_at !== null) {
                $checkpoint = Inventory::checkpoint($row->checkpoint);
                $checkpoint['rollback_refused'] = 'first_durable_claim_roll_forward_only';
                $this->journals()->where('id', $id)->update(['checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);

                return true;
            }

            return false;
        });
        if ($refused) {
            return 'rollback_refused_held';
        }
        if (! $this->drain($waitMs, true)) {
            $this->recordDrainHold($id, $expectedGeneration);

            return 'drain_timeout_held';
        }

        return $this->locked(function ($selector) use ($id, $expectedGeneration): string {
            $this->expect($selector, 'proxyma', $expectedGeneration, $id);
            $row = $this->requiredRow($id);
            if ($row->first_authorized_at !== null) {
                throw new DomainException('New claim prevents rollback.');
            }
            // Full source equality and imported counters/epochs/receipts; no state reset.
            $this->verify($id);
            $this->selector()->update(['authority' => 'legacy', 'generation' => $expectedGeneration + 1,
                'paused' => false, 'legacy_frozen' => false, 'cutover_id' => null]);

            return 'rolled_back';
        });
    }

    private function verify(string $id): void
    {
        $i = $this->journal->inventory;
        $i->lockSource();
        $report = $i->dryRun();
        $row = $this->requiredRow($id);
        $checkpoint = Inventory::checkpoint($row->checkpoint);
        if ($row->source_revision !== $report['source_revision'] || $row->target_revision !== $report['target_revision']
            || ($checkpoint['state'] ?? null) !== $report['checksum'] || array_filter($report['undrained']) !== []
            || array_filter($report['unmapped_ids']) !== [] || array_filter($report['orphans']) !== []) {
            throw new DomainException('Source equality, schema, drain or mapping proof failed; keep paused.');
        }
        if (isset($checkpoint['rollback_target_checksum']) && $checkpoint['rollback_target_checksum'] !== $this->targetChecksum()) {
            throw new DomainException('Exact target equality failed; keep paused for roll-forward.');
        }
        /** @var object{target_table: string, identity: string, imported: string} $record */
        foreach ($i->db->table(new TransferRecord()->getTable())->where('cutover_id', $id)->get() as $record) {
            $this->journal->put($id, $record->target_table, Inventory::jsonObject($record->identity),
                Inventory::jsonObject($record->imported));
        }
        // Every imported profile membership must retain the same complete route set.
        foreach ($i->db->table('prc_network_route_profiles')->pluck('id') as $profile) {
            $source = $i->db->table('prc_network_route_profile_members')->where('profile_id', $profile)->orderBy('route_id')->pluck('route_id')->all();
            $target = $i->db->table(PackageSchema::tables()['route_profile_members'])->where('profile_id', $profile)->orderBy('route_id')->pluck('route_id')->all();
            if ($source !== $target) {
                throw new DomainException('Eligibility route set differs.');
            }
        }
    }

    private function drain(int $waitMs, bool $package): bool
    {
        $deadline = hrtime(true) + $waitMs * 1_000_000;
        do {
            $db = $this->journal->inventory->db;
            $busy = $db->transaction(function () use ($db, $package): bool {
                $db->statement("SET LOCAL statement_timeout = '1000ms'");
                $busy = array_filter($this->journal->inventory->undrained()) !== [];
                $t = PackageSchema::tables();

                return $busy || ($package && ($db->table($t['permits'])->whereIn('state', ['reserved', 'authorized'])->exists()
                    || $db->table($t['operations'])->where('status', 'active')->exists()));
            });
            if (! $busy) {
                return true;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(10_000);
        } while (true);
    }

    private function targetChecksum(): string
    {
        $i = $this->journal->inventory;
        $rows = [];
        /** @var list<object{tablename: string}> $tables */
        $tables = $i->db->select("SELECT tablename FROM pg_tables WHERE schemaname=current_schema()
            AND (tablename LIKE 'pxm\_%' OR tablename LIKE 'prc_proxyma\_%') ORDER BY tablename");
        if ($tables !== []) {
            $i->db->statement('LOCK TABLE '.implode(', ', array_map(fn ($t) => $i->db->getQueryGrammar()->wrap($t->tablename), $tables)).' IN SHARE MODE');
        }
        foreach ($tables as $table) {
            $rows[$table->tablename] = $i->db->table($table->tablename.' as s')->selectRaw('to_jsonb(s)::text AS payload')
                ->orderByRaw('to_jsonb(s)::text COLLATE "C"')->pluck('payload')->all();
        }

        return $i->checksum($rows);
    }

    private function bounds(int $waitMs): void
    {
        if ($waitMs < 0 || $waitMs > 60_000) {
            throw new DomainException('Drain wait must be between 0 and 60000 ms.');
        }
    }

    private function recordDrainHold(string $id, int $generation): void
    {
        $this->locked(function ($selector) use ($id, $generation): void {
            if ($selector->generation !== $generation || $selector->cutover_id !== $id || ! $selector->paused) {
                throw new DomainException('Pause ownership changed during drain.');
            }
            $checkpoint = Inventory::checkpoint($this->requiredRow($id)->checkpoint);
            $checkpoint['drain_outcome'] = 'held_unresolved_not_proven_unsent';
            $this->journals()->where('id', $id)->update(['checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
        });
    }

    /** @param Selector $selector */
    private function expect(object $selector, string $authority, int $generation, ?string $id = null): void
    {
        if ($selector->authority !== $authority || $selector->generation !== $generation
            || ($id !== null && $selector->cutover_id !== $id)) {
            throw new DomainException('Authority generation or cutover changed.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(Selector): T  $action
     * @return T
     */
    private function locked(Closure $action): mixed
    {
        $db = $this->journal->inventory->db;

        return $db->transaction(function () use ($db, $action): mixed {
            $db->statement("SET LOCAL lock_timeout = '1000ms'");
            $db->statement("SET LOCAL statement_timeout = '10000ms'");

            /** @var Selector $selector */
            $selector = $this->selector()->lockForUpdate()->sole();

            return $action($selector);
        });
    }

    private function selector(): Builder
    {
        return $this->journal->inventory->db->table(new NetworkAuthority()->getTable())->where('id', 'network');
    }

    private function journals(): Builder
    {
        return $this->journal->inventory->db->table(new Journal()->getTable());
    }

    /** @return JournalRow|null */
    private function row(string $id): ?object
    {
        /** @var JournalRow|null $row */
        $row = $this->journals()->where('id', $id)->first();

        return $row;
    }

    /** @return JournalRow */
    private function requiredRow(string $id): object
    {
        return $this->row($id) ?? throw new DomainException('Cutover journal is missing.');
    }

    private function observed(string $phase): void
    {
        $this->afterPhase?->__invoke($phase);
    }
}
