<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('миграция создаёт семнадцать индексов по каталогу и удаляет при откате только свои индексы', function (): void {
    $migration = require database_path('migrations/2026_11_25_100000_add_audit_performance_indexes.php');
    $inventory = json_decode(file_get_contents(base_path('tests/Support/ProcessCore/schema-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
    $tables = $inventory['provenance']['audit_performance_indexes']['tables'];
    $expected = [];
    foreach ($tables as $table) {
        $expected += array_column($inventory['baseline']['tables'][$table]['indexes'], 'indexdef', 'indexname');
    }
    ksort($expected);

    $installed = static fn (): array => DB::table('pg_indexes')->where('schemaname', 'public')
        ->whereIn('tablename', $tables)->orderBy('indexname')->pluck('indexdef', 'indexname')->all();

    expect($installed())->toBe($expected);

    $migration->down();
    $afterRollback = $installed();
    expect(array_diff_assoc($expected, $afterRollback))->toHaveCount(17)
        ->and(array_diff_assoc($afterRollback, $expected))->toBe([]);

    $migration->up();
    expect($installed())->toBe($expected);
});

test('откат миграций Proxyma удаляет таблицы и функцию переноса, сохраняя родительские таблицы', function (): void {
    // В отдельной схеме воспроизводим состояние до миграции cutover fences.
    DB::statement('CREATE SCHEMA audit_proxyma_rollback');
    DB::statement('SET LOCAL search_path TO audit_proxyma_rollback');
    $parents = ['prc_source_targets', 'prc_network_route_profiles', 'prc_crawlers', 'prc_process_attempts', 'prc_proxies'];
    foreach ($parents as $table) {
        DB::statement("CREATE TABLE {$table} (LIKE public.{$table} INCLUDING INDEXES)");
    }
    $tables = ['prc_proxyma_target_links', 'prc_proxyma_profile_links', 'prc_proxyma_binding_links', 'prc_proxyma_owner_links',
        'prc_network_cutover_journals', 'prc_network_authorities', 'prc_proxyma_endpoint_links', 'prc_proxyma_transfer_records', 'prc_proxyma_state_archive'];
    $hasFunction = static fn (): bool => DB::scalar("SELECT to_regprocedure('audit_proxyma_rollback.prc_proxyma_transfer_immutable()') IS NOT NULL");
    $adapter = require database_path('migrations/2026_11_11_100000_create_prc_proxyma_adapter_links.php');
    $transfer = require database_path('migrations/2026_11_11_100001_create_prc_proxyma_transfer_records.php');
    $adapter->up();
    $transfer->up();

    expect($hasFunction())->toBeTrue();
    foreach ($tables as $table) {
        expect(Schema::hasTable('audit_proxyma_rollback.'.$table))->toBeTrue();
    }

    $transfer->down();
    $adapter->down();

    expect($hasFunction())->toBeFalse();
    foreach ($tables as $table) {
        expect(Schema::hasTable('audit_proxyma_rollback.'.$table))->toBeFalse();
    }
    foreach ($parents as $table) {
        expect(Schema::hasTable('audit_proxyma_rollback.'.$table))->toBeTrue();
    }
});
