<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Organization\ProcurementParty;
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
                ->constrained(table: (new Organization)->getTable())
                ->cascadeOnDelete();
            $table->string('role');
            $table->unsignedBigInteger('first_seen_revision_id')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamps();

            $table->unique(['procurement_id', 'organization_id', 'role']);
        });
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
