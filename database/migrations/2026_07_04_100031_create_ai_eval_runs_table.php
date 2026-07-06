<?php

declare(strict_types=1);

use App\Models\Procurement\EvalRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('dataset_version');
            $table->text('provider');
            $table->text('model');
            $table->jsonb('prompt_version_set')->nullable();
            $table->jsonb('metrics')->nullable()->comment('precision/recall/F1, row_coverage');
            $table->text('ci_status')->comment('pass/fail');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new EvalRun)->getTable();
    }
};
