<?php

declare(strict_types=1);

use App\Models\Ai\AiRoute;
use App\Models\Ai\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('document_type')->comment("code из prc_document_types или зарезервированное 'any' — без строгого FK");
            $table->text('purpose')->comment('совпадает с prc_ai_llm_calls.purpose');

            $table->foreignId('provider_id')
                ->index()
                ->constrained(table: (new Provider)->getTable());

            $table->text('model');
            $table->jsonb('options')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('shadow')->default(false)->comment('теневой прогон, не значение purpose');

            $table->timestamps();

            $table->unique(['document_type', 'purpose', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new AiRoute)->getTable();
    }
};
