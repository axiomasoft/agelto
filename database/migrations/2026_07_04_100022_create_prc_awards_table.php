<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\Award;
use App\Models\Procurement\Lot;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('procurement_id')
                ->index()
                ->constrained(table: (new Procurement)->getTable());

            $table->foreignId('lot_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Lot)->getTable())
                ->nullOnDelete();

            $table->foreignId('winner_org_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Organization)->getTable())
                ->nullOnDelete();

            $table->decimal('final_price', total: 18, places: 2)->nullable();
            $table->char('currency', length: 3)->nullable();
            $table->text('status');
            $table->text('contract_registry_number')->nullable();
            $table->timestampTz('awarded_at')->nullable();
            $table->jsonb('payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Award)->getTable();
    }
};
