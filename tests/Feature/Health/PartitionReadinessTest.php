<?php

declare(strict_types=1);

use App\Enums\Health\ReadinessCheckCode;
use App\Services\Health\ApplicationReadiness;
use App\Services\PartitionCoverage;
use Illuminate\Support\Facades\DB;

test('проверка базы данных проходит при наличии текущих и следующих месячных партиций', function (): void {
    $result = app(ApplicationReadiness::class)->check(only: [ReadinessCheckCode::Database]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->passed)->toBeTrue();
});

test('проверка базы данных сообщает о недостающей партиции следующего месяца', function (): void {
    DB::statement('CREATE TABLE test_readiness_partition_probe (created_at timestamptz NOT NULL) PARTITION BY RANGE (created_at)');
    config()->set('partitioning.tables', ['test_readiness_partition_probe' => 'created_at']);

    $month = now('UTC')->startOfMonth();
    $nextMonth = $month->copy()->addMonthNoOverflow();
    $partitions = app(PartitionCoverage::class);
    $partitions->ensure('test_readiness_partition_probe', $month, $nextMonth);
    $partitions->ensure('test_readiness_partition_probe', $nextMonth, $nextMonth->copy()->addMonthNoOverflow());

    expect(app(ApplicationReadiness::class)->check(only: [ReadinessCheckCode::Database])[0]->passed)->toBeTrue()
        ->and($partitions->hasCoverageFor('test_readiness_partition_probe', $nextMonth))->toBeTrue();

    DB::statement('DROP TABLE test_readiness_partition_probe_'.$nextMonth->format('Y_m'));

    expect($partitions->hasCoverageFor('test_readiness_partition_probe', $nextMonth))->toBeFalse()
        ->and(app(ApplicationReadiness::class)->check(only: [ReadinessCheckCode::Database])[0]->passed)->toBeFalse();

    expect(fn (): bool => $partitions->hasCoverageFor('test_readiness_partition_probe;drop', $nextMonth))
        ->toThrow(RuntimeException::class, 'Invalid partition parent identifier.');
});

test('создание партиций ограничивает ожидание блокировки тремя секундами до захвата таблицы', function (): void {
    DB::statement('CREATE TABLE test_partition_timeout_probe (created_at timestamptz NOT NULL) PARTITION BY RANGE (created_at)');

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $month = now('UTC')->startOfMonth();
        app(PartitionCoverage::class)->ensure('test_partition_timeout_probe', $month, $month->copy()->addMonthNoOverflow());
        $statements = array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $timeout = array_search("SET LOCAL lock_timeout = '3s'", $statements, true);
    $lock = array_search('LOCK TABLE test_partition_timeout_probe IN SHARE UPDATE EXCLUSIVE MODE', $statements, true);

    expect($timeout)->toBeInt()
        ->and($lock)->toBeInt()
        ->and($timeout)->toBeLessThan($lock)
        ->and(DB::scalar("SELECT current_setting('lock_timeout')"))->toBe('3s');
});
