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
                ->constrained(table: (new Estimate)->getTable())
                ->cascadeOnDelete();

            // Иерархия итогов («Итого» → «в т.ч. НДС»), перенос рекурсии EstimateFooterItem из v1.
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained(table: $this->table())
                ->nullOnDelete();

            $table->integer('position');
            $table->text('label');

            // text + PHP backed enum TotalKind (D101), не Postgres native enum. Доопределение по
            // label этим планом не строится (долг, §7) — пусто при молчании модели.
            $table->text('kind')->nullable()->comment('overhead/profit/vat/total/additional/other');

            // Nullable (D101): начисление-процент может не иметь суммы вовсе — признак «задано
            // процентом» это rate IS NOT NULL, фиктивный ноль не пишется.
            $table->decimal('amount', total: 18, places: 2)->nullable();

            // SR-11 (D101): глава сводного расчёта и ставка начисления, заданного процентом.
            $table->smallInteger('chapter_number')->nullable();
            $table->decimal('rate', total: 18, places: 6)->nullable();

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
