<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Section;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('estimate_id')
                ->index()
                ->constrained(table: (new Estimate)->getTable())
                ->cascadeOnDelete();

            // Дерево разделов (без ltree на этом шаге, см. 01-database.md §6b «опц. позже»).
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained(table: $this->table())
                ->nullOnDelete();

            $table->integer('position');
            $table->text('title');
            $table->text('section_number')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Section)->getTable();
    }
};
