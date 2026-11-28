<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Organization\OrganizationIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained(table: $this->organizations())->cascadeOnDelete();
            $table->string('platform_code', 64);
            $table->string('type', 64);
            $table->string('value');
            $table->unique(['platform_code', 'type', 'value'], 'org_identifier_identity_unique');
            $table->unique(['organization_id', 'platform_code', 'type'], 'org_identifier_owner_type_unique');
        });

        // These columns have already been used locally. Preserve their data on upgrade.
        foreach ($this->legacyColumns() as $column => $type) {
            DB::table($this->table())->insertUsing(
                ['organization_id', 'platform_code', 'type', 'value'],
                DB::table($this->organizations())->whereNotNull($column)
                    ->select('id', DB::raw("'eis'"), DB::raw("'{$type}'"), $column),
            );
        }

        Schema::table($this->organizations(), function (Blueprint $table): void {
            $table->dropColumn(array_keys($this->legacyColumns()));
        });
    }

    public function down(): void
    {
        Schema::table($this->organizations(), function (Blueprint $table): void {
            $table->string('eis_organization_code')->nullable()->unique();
            $table->string('eis_customer_code', 20)->nullable()->unique();
            $table->string('eruz_number')->nullable()->index();
        });

        foreach ($this->legacyColumns() as $column => $type) {
            DB::statement("UPDATE {$this->organizations()} AS organizations
                SET {$column} = identifiers.value FROM {$this->table()} AS identifiers
                WHERE identifiers.organization_id = organizations.id
                AND identifiers.platform_code = 'eis' AND identifiers.type = ?", [$type]);
        }

        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new OrganizationIdentifier)->getTable();
    }

    private function organizations(): string
    {
        return (new Organization)->getTable();
    }

    /** @return array<string, string> */
    private function legacyColumns(): array
    {
        return [
            'eis_organization_code' => 'organization_code',
            'eis_customer_code' => 'customer_code',
            'eruz_number' => 'eruz_number',
        ];
    }
};
