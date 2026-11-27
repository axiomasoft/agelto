<?php

declare(strict_types=1);

use App\Dto\Procurement\StatusResolution;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const StatusMappingApplicabilityMigration = 'database/migrations/2026_08_09_100092_add_applicability_to_prc_status_mappings_table.php';

function rollbackStatusMappingApplicabilityMigration(): void
{
    expect(Artisan::call(command: 'migrate:rollback', parameters: [
        '--path' => StatusMappingApplicabilityMigration,
        '--force' => true,
    ]))->toBe(0);
}

function migrateStatusMappingApplicability(): int
{
    return Artisan::call(command: 'migrate', parameters: [
        '--path' => StatusMappingApplicabilityMigration,
        '--force' => true,
    ]);
}

test('backfills existing rules as exact registry applicability and can rollback then reapply losslessly', function (): void {
    rollbackStatusMappingApplicabilityMigration();

    $registry = Registry::factory()->create();
    $mapping = StatusMapping::query()->create([
        'registry_id' => $registry->id,
        'source_stage' => 'Legacy stage',
        'canonical_status' => ProcurementStatus::Bidding->value,
    ]);

    expect(migrateStatusMappingApplicability())->toBe(0);

    $mapping->refresh();

    expect($mapping->registry_id)->toBe($registry->id)
        ->and($mapping->platform_id)->toBeNull()
        ->and($mapping->source_stage)->toBe('Legacy stage')
        ->and($mapping->canonical_status)->toBe(ProcurementStatus::Bidding->value)
        ->and($mapping->applicability)->toBe(StatusMappingApplicability::Registry);

    rollbackStatusMappingApplicabilityMigration();

    expect(Schema::hasColumn((new StatusMapping)->getTable(), 'platform_id'))->toBeFalse()
        ->and(StatusMapping::query()->findOrFail($mapping->id)->registry_id)->toBe($registry->id);

    expect(migrateStatusMappingApplicability())->toBe(0);
});

test('database enforces one applicability and independent partial unique keys', function (): void {
    $table = (new StatusMapping)->getTable();
    $platform = Platform::factory()->create();
    $registry = Registry::factory()->for($platform)->create();

    expect(fn (): bool => DB::transaction(fn (): bool => DB::table($table)->insert([
        'source_stage' => 'Neither',
        'canonical_status' => ProcurementStatus::Unknown->value,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'prc_status_mappings_exactly_one_applicability_check');

    expect(fn (): bool => DB::transaction(fn (): bool => DB::table($table)->insert([
        'platform_id' => $platform->id,
        'registry_id' => $registry->id,
        'source_stage' => 'Both',
        'canonical_status' => ProcurementStatus::Unknown->value,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'prc_status_mappings_exactly_one_applicability_check');

    StatusMapping::factory()->forPlatform($platform)->create(['source_stage' => 'Shared']);
    StatusMapping::factory()->create(['registry_id' => $registry->id, 'source_stage' => 'Shared']);

    expect(fn (): StatusMapping => DB::transaction(fn (): StatusMapping => StatusMapping::factory()->forPlatform($platform)->create(['source_stage' => 'Shared'])))
        ->toThrow(QueryException::class, 'prc_status_mappings_platform_source_stage_unique');
    expect(fn (): StatusMapping => DB::transaction(fn (): StatusMapping => StatusMapping::factory()->create(['registry_id' => $registry->id, 'source_stage' => 'Shared'])))
        ->toThrow(QueryException::class, 'prc_status_mappings_registry_source_stage_unique');
});

test('catalog exposes the applicability check and partial unique indexes', function (): void {
    $table = (new StatusMapping)->getTable();

    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', $table)
        ->pluck('indexname')
        ->all();
    $check = DB::table('pg_constraint')
        ->where('conname', 'prc_status_mappings_exactly_one_applicability_check')
        ->exists();

    expect($indexes)->toContain(
        'prc_status_mappings_platform_source_stage_unique',
        'prc_status_mappings_registry_source_stage_unique',
    )->and($check)->toBeTrue();
});

test('status resolution carries matched applicability as a typed value', function (): void {
    $resolution = new StatusResolution(
        status: ProcurementStatus::Bidding,
        isNewlyMapped: false,
        matchedApplicability: StatusMappingApplicability::Platform,
    );

    expect($resolution->matchedApplicability)->toBe(StatusMappingApplicability::Platform);
});

test('rollback refuses before schema mutation when platform applicability exists', function (): void {
    $mapping = StatusMapping::factory()->forPlatform()->create();
    $table = (new StatusMapping)->getTable();

    expect(fn () => rollbackStatusMappingApplicabilityMigration())
        ->toThrow(RuntimeException::class, 'Cannot safely roll back status mapping applicability');

    expect(Schema::hasColumn($table, 'platform_id'))->toBeTrue()
        ->and(StatusMapping::query()->findOrFail($mapping->id)->applicability)->toBe(StatusMappingApplicability::Platform);
});
