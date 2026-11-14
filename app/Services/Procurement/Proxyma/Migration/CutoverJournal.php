<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use App\Models\Procurement\Proxyma\CutoverJournal as Journal;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\TransferRecord;
use Closure;
use DomainException;
use Proxyma\Domain\Execution\Uuid;

final readonly class CutoverJournal
{
    public function __construct(public Inventory $inventory) {}

    /** One atomic step. A failed step leaves earlier steps available for a checked rerun. */
    public function step(string $id, string $step, Closure $transfer): void
    {
        new Uuid($id);
        $db = $this->inventory->db;
        $db->transaction(function () use ($db, $id, $step, $transfer): void {
            $db->statement("SET LOCAL lock_timeout = '1000ms'");
            $db->statement("SET LOCAL statement_timeout = '10000ms'");
            /** @var object{authority: string, generation: int} $selector */
            $selector = $db->table((new NetworkAuthority)->getTable())->where('id', 'network')->lockForUpdate()->sole();
            CutoverFence::install($db);
            if ($selector->authority !== 'legacy') {
                throw new DomainException('Transfer requires inactive package authority.');
            }
            $this->inventory->lockSource();
            $report = $this->inventory->dryRun();
            if (array_filter($report['orphans']) !== []) {
                throw new DomainException('Unmapped source references block transfer.');
            }
            $table = (new Journal)->getTable();
            /** @var object{source_revision: string, target_revision: string, generation: int, phase: string, checkpoint: string}|null $row */
            $row = $db->table($table)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                $db->table($table)->insert(['id' => $id, 'source_revision' => $report['source_revision'],
                    'target_revision' => $report['target_revision'], 'phase' => 'prepared', 'generation' => $selector->generation + 1,
                    'checkpoint' => json_encode(['catalog' => null, 'state' => null], JSON_THROW_ON_ERROR)]);
                /** @var object{source_revision: string, target_revision: string, generation: int, phase: string, checkpoint: string} $row */
                $row = $db->table($table)->where('id', $id)->sole();
            }
            if ($row->source_revision !== $report['source_revision'] || $row->target_revision !== $report['target_revision']
                || $row->generation !== $selector->generation + 1 || ! in_array($row->phase, ['prepared', 'draining'], true)) {
                throw new DomainException('Schema, generation or phase changed since preparation.');
            }
            $checkpoint = Inventory::checkpoint($row->checkpoint);
            if (($checkpoint['catalog'] ?? null) !== null && $checkpoint['catalog'] !== $this->inventory->catalogChecksum($report['tables'])) {
                throw new DomainException('Source catalog changed after partial transfer.');
            }
            $digest = $step === 'catalog' ? $this->inventory->catalogChecksum($report['tables']) : $report['checksum'];
            if (($checkpoint[$step] ?? null) !== null && $checkpoint[$step] !== $digest) {
                throw new DomainException('Source changed after partial transfer; reconcile without overwriting imported state.');
            }
            /** @var object{target_table: string, identity: string, imported: string} $record */
            foreach ($db->table((new TransferRecord)->getTable())->where('cutover_id', $id)->get() as $record) {
                $this->put($id, $record->target_table, Inventory::jsonObject($record->identity),
                    Inventory::jsonObject($record->imported));
            }
            if ($step === 'state' && ! isset($checkpoint['state_as_of'])) {
                /** @var object{now: string} $clock */
                $clock = $db->selectOne('SELECT clock_timestamp()::text AS now');
                $checkpoint['state_as_of'] = $clock->now;
                $db->table($table)->where('id', $id)->update(['checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
            }
            $transfer();
            $checkpoint[$step] = $digest;
            $db->table($table)->where('id', $id)->update(['phase' => 'draining', 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
        });
    }

    /** Insert or compare; never UPDATE a target, including newer catalog/health/budget rows.
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $attributes
     */
    public function put(string $cutover, string $table, array $identity, array $attributes): void
    {
        $db = $this->inventory->db;
        $key = $this->inventory->checksum($identity);
        $records = $db->table((new TransferRecord)->getTable());
        $record = (clone $records)->where('target_table', $table)->where('target_key', $key)->lockForUpdate()->first();
        $query = $db->table($table)->where($identity);
        $actual = (clone $query)->lockForUpdate()->first();
        $expected = $identity + $attributes;
        if ($record !== null && $record->checksum !== $this->inventory->checksum($expected)) {
            throw new DomainException('Imported source revision/checksum changed.');
        }
        if ($actual !== null) {
            // Compare SQL-normalized JSON, numbers and timestamps, without reading secret material externally.
            foreach ($expected as $column => $value) {
                // Идентификатор экранирован grammar, тип получен только из pg_attribute.
                /** @var literal-string&non-falsy-string $predicate */
                $predicate = 'to_jsonb('.$db->getQueryGrammar()->wrap($column).') IS NOT DISTINCT FROM to_jsonb(CAST(? AS '.$this->columnType($table, $column).'))';
                $matches = (clone $query)->whereRaw($predicate, [$value]);
                if (! $matches->exists()) {
                    throw new DomainException('Existing target differs; newer state must not be overwritten: '.$table.'.'.$column);
                }
            }
        } else {
            if ($record !== null) {
                throw new DomainException('Previously imported row is missing.');
            }
            $db->table($table)->insert($expected);
        }
        if ($record === null) {
            $records->insert(['target_table' => $table, 'target_key' => $key, 'cutover_id' => $cutover,
                'identity' => json_encode($identity, JSON_THROW_ON_ERROR), 'imported' => json_encode($expected, JSON_THROW_ON_ERROR),
                'checksum' => $this->inventory->checksum($expected)]);
        }
    }

    private function columnType(string $table, string $column): string
    {
        /** @var object{type: string}|null $row */
        $row = $this->inventory->db->selectOne('SELECT format_type(a.atttypid,a.atttypmod) AS type FROM pg_attribute a WHERE a.attrelid=?::regclass AND a.attname=? AND NOT a.attisdropped', [$table, $column]);

        if ($row === null) {
            throw new DomainException('Unknown target column.');
        }

        return $row->type;
    }
}
