<?php

declare(strict_types=1);

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Models\Organization\Organization;
use App\Models\Organization\OrganizationIdentifier;
use App\Services\Organization\OrganizationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('переход на внешние идентификаторы сохраняет старые коды и допускает обратный перенос', function () {
    $migration = require database_path('migrations/2026_10_23_100000_create_org_organization_identifiers_table.php');
    $migration->down();
    $organization = Organization::factory()->create(['inn' => null, 'kpp' => null]);
    // The current model no longer exposes legacy columns; seed the old schema explicitly.
    DB::table($organization->getTable())->where('id', $organization->id)->update([
        'eis_organization_code' => '01013000002',
        'eis_customer_code' => '30253020551025301001',
        'eruz_number' => '0000000000042',
    ]);

    $migration->up();

    expect(Schema::hasColumn($organization->getTable(), 'eis_organization_code'))->toBeFalse()
        ->and(Schema::hasColumn($organization->getTable(), 'eis_customer_code'))->toBeFalse()
        ->and(Schema::hasColumn($organization->getTable(), 'eruz_number'))->toBeFalse()
        ->and(OrganizationIdentifier::query()->count())->toBe(3);

    foreach (['organization_code' => '01013000002', 'customer_code' => '30253020551025301001', 'eruz_number' => '0000000000042'] as $type => $value) {
        $resolved = app(OrganizationResolver::class)->resolve(new OrganizationData(fullName: $organization->full_name, identifiers: [
            new OrganizationIdentifierData(platformCode: 'eis', type: $type, value: $value),
        ]));
        expect($resolved->id)->toBe($organization->id);
    }

    $migration->down();
    $legacy = DB::table($organization->getTable())->find($organization->id);
    expect($legacy->eis_organization_code)->toBe('01013000002')
        ->and($legacy->eis_customer_code)->toBe('30253020551025301001')
        ->and($legacy->eruz_number)->toBe('0000000000042');
    $migration->up();
});
