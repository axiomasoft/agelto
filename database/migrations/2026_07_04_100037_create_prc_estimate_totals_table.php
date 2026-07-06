<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Total;
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
                ->constrained(table: (new Estimate)->getTable());

            // Иерархия итогов («Итого» → «в т.ч. НДС»), перенос рекурсии EstimateFooterItem из v1.
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained(table: $this->table())
                ->nullOnDelete();

            $table->integer('position');
            $table->text('label');
            $table->text('kind')->nullable()->comment('НР/СП/НДС/…');
            $table->decimal('amount', total: 18, places: 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Total)->getTable();
    }
};
