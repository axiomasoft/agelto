<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registry_id')
                ->constrained(table: (new Registry)->getTable())
                ->cascadeOnDelete();
            $table->string('source_status');
            $table->string('canonical_status');
            $table->timestamps();

            $table->unique(['registry_id', 'source_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new StatusMapping)->getTable();
    }
};
