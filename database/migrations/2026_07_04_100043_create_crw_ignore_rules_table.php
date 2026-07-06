<?php

declare(strict_types=1);

use App\Models\Procurement\IgnoreRule;
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
                ->nullable()
                ->index()
                ->constrained(table: (new Registry)->getTable());

            $table->text('field');
            $table->text('operator')->comment('contains/regex');
            $table->text('value');
            $table->text('reason_category');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new IgnoreRule)->getTable();
    }
};
