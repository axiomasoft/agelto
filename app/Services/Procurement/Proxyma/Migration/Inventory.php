<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class Inventory
{
    public const array Tables = [
        'prc_proxies', 'prc_network_routes', 'prc_network_route_profiles', 'prc_network_route_profile_members',
        'prc_source_targets', 'prc_crawlers', 'prc_process_runs', 'prc_network_budgets',
        'prc_network_demands', 'prc_network_permits', 'prc_network_permit_scopes',
        'prc_route_health', 'prc_route_target_health', 'prc_target_health', 'prc_adapter_health',
        'prc_process_handler_holds', 'prc_process_attempts', 'prc_outbound_operations',
        'prc_request_attempts', 'prc_observations', 'prc_observation_applications',
    ];

    public function __construct(public Connection $db, private string $checksumKey)
    {
        if (strlen($checksumKey) < 32) {
            throw new DomainException('Inventory requires a private stable checksum key of at least 32 bytes.');
        }
    }

    public function checksum(mixed $value): string
    {
        return hash_hmac('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR), $this->checksumKey);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table): array
    {
        if (! in_array($table, self::Tables, true)) {
            throw new DomainException('Unknown inventory table.');
        }

        /** @var list<object{payload: string}> $rows */
        $rows = $this->db->select("SELECT to_jsonb(s)::text AS payload FROM {$table} s ORDER BY to_jsonb(s)::text COLLATE \"C\"");

        return array_map(static fn ($r): array => self::jsonObject($r->payload), $rows);
    }

    /** @return array<string, mixed> */
    public static function jsonObject(mixed $json): array
    {
        if (! is_string($json) || ! str_starts_with(ltrim($json), '{')) {
            throw new DomainException('Expected a JSON object column.');
        }
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_any(array_keys($value), static fn ($key): bool => ! is_string($key))) {
            throw new DomainException('Expected a JSON object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, string|null> */
    public static function checkpoint(mixed $json): array
    {
        $value = self::jsonObject($json);
        foreach ($value as $entry) {
            if ($entry !== null && ! is_string($entry)) {
                throw new DomainException('Invalid cutover checkpoint value.');
            }
        }

        /** @var array<string, string|null> $value */
        return $value;
    }

    public function sourceRevision(): string
    {
        return $this->schemaRevision(self::Tables);
    }

    /** @param array<string, mixed> $tables */
    public function catalogChecksum(array $tables): string
    {
        return $this->checksum(array_intersect_key($tables, array_flip([
            'prc_proxies', 'prc_network_routes', 'prc_network_route_profiles', 'prc_network_route_profile_members',
            'prc_source_targets', 'prc_crawlers', 'prc_process_runs',
        ])));
    }

    public function targetRevision(): string
    {
        /** @var list<object{tablename: string}> $tables */
        $tables = $this->db->select("SELECT tablename FROM pg_tables
            WHERE schemaname=current_schema() AND (tablename LIKE 'pxm\_%' OR tablename LIKE 'prc_proxyma\_%'
                OR tablename IN ('prc_network_authorities','prc_network_cutover_journals')) ORDER BY tablename");

        return $this->schemaRevision(array_map(static fn ($r): string => $r->tablename, $tables));
    }

    /** @param list<string> $tables */
    private function schemaRevision(array $tables): string
    {
        $names = json_encode($tables, JSON_THROW_ON_ERROR);

        return $this->checksum([
            'columns' => $this->db->select('SELECT table_name,column_name,udt_name,is_nullable,column_default FROM information_schema.columns
                WHERE table_schema=current_schema() AND table_name IN (SELECT jsonb_array_elements_text(?::jsonb)) ORDER BY table_name,ordinal_position', [$names]),
            'constraints' => $this->db->select('SELECT t.relname,c.conname,pg_get_constraintdef(c.oid) AS definition
                FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
                WHERE n.nspname=current_schema() AND t.relname IN (SELECT jsonb_array_elements_text(?::jsonb)) ORDER BY t.relname,c.conname', [$names]),
            'indexes' => $this->db->select('SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname=current_schema()
                AND tablename IN (SELECT jsonb_array_elements_text(?::jsonb)) ORDER BY tablename,indexname', [$names]),
        ]);
    }

    /** @return array{source_revision: string, target_revision: string, checksum: string, tables: array<string, array{count: int, checksum: string}>, unmapped_ids: array<string, list<int|string>>, orphans: array<string, list<int|string>>, undrained: array<string, list<string>>, quota_source: string} */
    public function dryRun(): array
    {
        return $this->db->transaction(function (): array {
            $this->db->statement("SET LOCAL lock_timeout = '1000ms'");
            $this->db->statement("SET LOCAL statement_timeout = '10000ms'");
            $this->lockSource();
            $tables = [];
            foreach (self::Tables as $table) {
                $hash = hash_init('sha256', HASH_HMAC, $this->checksumKey);
                $count = 0;
                $cursor = $this->db->table($table.' as s')->selectRaw('to_jsonb(s)::text AS payload')->orderByRaw('to_jsonb(s)::text COLLATE "C"')->cursor();
                /** @var object{payload: string} $row */
                foreach ($cursor as $row) {
                    hash_update($hash, strlen($row->payload).':'.$row->payload);
                    $count++;
                }
                $tables[$table] = ['count' => $count, 'checksum' => hash_final($hash)];
            }
            $unmapped = [];
            foreach ([
                'proxy' => ['prc_proxies', 'id', 'prc_proxyma_endpoint_links', 'proxy_id'],
                'target' => ['prc_source_targets', 'id', 'prc_proxyma_target_links', 'source_target_id'],
                'profile' => ['prc_network_route_profiles', 'id', 'prc_proxyma_profile_links', 'source_profile_id'],
            ] as $kind => [$source, $id, $link, $foreign]) {
                /** @var list<int|string> $ids */
                $ids = array_values($this->db->table($source.' as s')->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($link.' as l')
                    ->whereColumn('s.'.$id, 'l.'.$foreign))->orderBy('s.'.$id)->pluck('s.'.$id)->all());
                $unmapped[$kind] = $ids;
            }
            $unmapped['bindings'] = [];
            /** @var int $crawler */
            foreach ($this->db->table('prc_crawlers')->orderBy('id')->pluck('id') as $crawler) {
                foreach (['listing', 'detail', 'status_refresh', 'download'] as $slot) {
                    if (! $this->db->table('prc_proxyma_binding_links')->where('crawler_id', $crawler)->where('slot', $slot)->exists()) {
                        $unmapped['bindings'][] = $crawler.':'.$slot;
                    }
                }
            }
            $unmapped['owners'] = [];
            foreach (['prc_process_attempts' => 'process_attempt', 'prc_outbound_operations' => 'outbound_operation'] as $source => $type) {
                /** @var object{id: string, generation: int} $owner */
                foreach ($this->db->table($source)->orderBy('id')->get(['id', 'generation']) as $owner) {
                    if (! $this->db->table('prc_proxyma_owner_links')->where('owner_type', $type)->where('owner_id', $owner->id)->where('generation', $owner->generation)->exists()) {
                        $unmapped['owners'][] = $type.':'.$owner->id.':'.$owner->generation;
                    }
                }
            }
            $orphans = [];
            foreach ([
                ['prc_network_routes', 'id', 'prc_proxies', 'route_id', "s.kind='proxy'"],
                ['prc_crawlers', 'route_profile_id', 'prc_network_route_profiles', 'id', 's.route_profile_id IS NOT NULL'],
                ['prc_network_demands', 'target_id', 'prc_source_targets', 'id', 'true'],
            ] as [$source, $id, $parent, $foreign, $filter]) {
                /** @var list<object{id: int|string}> $rows */
                $rows = $this->db->select("SELECT s.{$id} AS id FROM {$source} s LEFT JOIN {$parent} p ON p.{$foreign}=s.{$id} WHERE {$filter} AND p.{$foreign} IS NULL");
                $orphans[$source] = array_map(static fn ($r): int|string => $r->id, $rows);
            }

            return ['source_revision' => $this->sourceRevision(), 'target_revision' => $this->targetRevision(),
                'checksum' => $this->checksum($tables), 'tables' => $tables, 'unmapped_ids' => $unmapped,
                'orphans' => $orphans, 'undrained' => $this->undrained(),
                'quota_source' => 'baseline has no fixed-window network quota tables; existing target windows are retained'];
        });
    }

    public function lockSource(): void
    {
        $this->db->statement('LOCK TABLE '.implode(', ', self::Tables).' IN SHARE MODE');
    }

    /** @return array<string, list<string>> */
    public function undrained(): array
    {
        /** @var array<string, list<string>> $ids */
        $ids = [
            'permits' => $this->db->table('prc_network_permits')->whereIn('state', ['reserved', 'sent'])->pluck('id')->all(),
            'demands' => $this->db->table('prc_network_demands')->where('state', 'queued')->pluck('id')->all(),
            'operations' => $this->db->table('prc_outbound_operations')->where('state', 'open')->pluck('id')->all(),
            'attempts' => $this->db->table('prc_process_attempts')->whereNull('finished_at')->pluck('id')->all(),
            'requests' => $this->db->table('prc_request_attempts')->whereNull('finished_at')->pluck('request_id')->all(),
        ];

        return $ids;
    }
}
