<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('eis_organization_code')->nullable()->unique();
            $table->string('eis_customer_code', 20)->nullable()->unique();
        });

        // Unknown tax identities must not collapse into the same (null, null) master.
        DB::statement('DROP INDEX org_organizations_inn_kpp_unique');
        DB::statement("CREATE UNIQUE INDEX org_organizations_inn_kpp_unique ON {$this->table()} (inn, kpp) NULLS NOT DISTINCT WHERE inn IS NOT NULL");
    }

    public function down(): void
    {
        // Fail atomically rather than merge/delete organizations with unknown tax identity.
        DB::statement('DROP INDEX org_organizations_inn_kpp_unique');
        DB::statement("CREATE UNIQUE INDEX org_organizations_inn_kpp_unique ON {$this->table()} (inn, kpp) NULLS NOT DISTINCT");

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['eis_organization_code', 'eis_customer_code']);
        });
    }

    private function table(): string
    {
        return (new Organization)->getTable();
    }
};
