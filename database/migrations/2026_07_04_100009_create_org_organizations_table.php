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
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('inn')->nullable()->index();
            $table->string('kpp')->nullable();
            $table->string('ogrn')->nullable();
            $table->string('eruz_number')->nullable()->index();
            $table->string('full_name');
            $table->string('short_name')->nullable();
            $table->text('address_raw')->nullable();
            $table->string('region_code')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamps();
        });

        // Postgres 17: NULLS NOT DISTINCT — двух записей с одинаковым inn+null-kpp быть не должно,
        // Schema Builder такой индекс не умеет строить.
        DB::statement(
            "CREATE UNIQUE INDEX org_organizations_inn_kpp_unique ON {$this->table()} (inn, kpp) NULLS NOT DISTINCT",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS org_organizations_inn_kpp_unique');

        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Organization)->getTable();
    }
};
