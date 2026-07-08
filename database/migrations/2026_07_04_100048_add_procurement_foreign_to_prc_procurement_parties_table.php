<?php

declare(strict_types=1);

use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->foreign('procurement_id')
                ->references('id')
                ->on((new Procurement)->getTable())
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropForeign(['procurement_id']);
        });
    }

    private function table(): string
    {
        return (new ProcurementParty)->getTable();
    }
};
