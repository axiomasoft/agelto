<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

test('горячие таблицы получают параметры хранения и откат сбрасывает их', function (): void {
    $expected = [
        'prc_process_work' => [
            'fillfactor' => '75',
            'autovacuum_vacuum_scale_factor' => '0.01',
            'autovacuum_vacuum_threshold' => '500',
            'autovacuum_analyze_scale_factor' => '0.02',
            'autovacuum_vacuum_cost_limit' => '2000',
            'autovacuum_vacuum_cost_delay' => '2',
        ],
        'prc_process_outbox' => [
            'fillfactor' => '75',
            'autovacuum_vacuum_scale_factor' => '0.005',
            'autovacuum_vacuum_threshold' => '200',
            'autovacuum_vacuum_cost_limit' => '2000',
            'autovacuum_vacuum_cost_delay' => '2',
        ],
        'prc_process_daemon_heartbeats' => [
            'fillfactor' => '50',
            'autovacuum_vacuum_scale_factor' => '0.0',
            'autovacuum_vacuum_threshold' => '50',
        ],
        'prc_process_lanes' => [
            'fillfactor' => '60',
            'autovacuum_vacuum_scale_factor' => '0.0',
            'autovacuum_vacuum_threshold' => '100',
        ],
    ];

    $options = static function (string $table): array {
        $row = DB::selectOne('SELECT reloptions FROM pg_class WHERE relname = ?', [$table]);
        expect($row)->not->toBeNull();

        if ($row->reloptions === null) {
            return [];
        }

        $result = [];
        foreach (str_getcsv(trim($row->reloptions, '{}')) as $option) {
            [$name, $value] = explode('=', $option, 2);
            $result[$name] = $value;
        }

        return $result;
    };

    foreach ($expected as $table => $parameters) {
        expect($options($table))->toBe($parameters, $table);
    }

    $migration = require database_path('migrations/2026_11_26_100000_tune_process_tables_autovacuum_and_fillfactor.php');
    $migration->down();
    foreach (array_keys($expected) as $table) {
        expect($options($table))->toBe([], $table);
    }

    $migration->up();
    foreach ($expected as $table => $parameters) {
        expect($options($table))->toBe($parameters, $table);
    }
});
