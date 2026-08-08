<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\ProcurementParty;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // Без constrained() — prc_procurements появится в другом item.
            $table->unsignedBigInteger('procurement_id')->index();

            $table->foreignId('organization_id')
                // P7.5, F-01: индекс ведущей колонки FK; таблица < 1 МБ на ревизии.
                ->index()
                ->constrained(table: (new Organization)->getTable())
                ->cascadeOnDelete();
            $table->string('role');
            $table->unsignedBigInteger('first_seen_revision_id')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamps();

            $table->unique(['procurement_id', 'organization_id', 'role']);
        });

        // FK procurement_id объявляется сразу после создания prc_procurements в миграции 100011:
        // create-миграции не перенумеровываются (D81), а родителя на этом шаге ещё нет.
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new ProcurementParty)->getTable();
    }
};
