<?php

declare(strict_types=1);

use App\Models\Procurement\IgnoredItem;
use App\Models\Procurement\Registry;
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
                ->index()
                ->constrained(table: (new Registry)->getTable());

            $table->text('source_number');
            $table->text('reason_category')->index();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->unique(['registry_id', 'source_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new IgnoredItem)->getTable();
    }
};
