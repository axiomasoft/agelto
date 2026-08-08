<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7.5 F-02/D133: обе аудируемые связи результата оценки сохраняют исходный `NO ACTION`
 * явно; изменение поведения удаления не входит в консолидацию.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('eval_run_id')
                ->index()
                ->constrained(table: (new EvalRun)->getTable())
                ->onDelete('NO ACTION');

            $table->foreignId('dataset_example_id')
                ->index()
                ->constrained(table: (new DatasetExample)->getTable())
                ->onDelete('NO ACTION');

            $table->text('metric');
            $table->decimal('value', total: 18, places: 6);
            $table->jsonb('details')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new EvalResult)->getTable();
    }
};
