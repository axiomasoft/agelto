<?php

declare(strict_types=1);

use App\Models\DocumentAnalysis\DocumentAnalysisResponse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            // Учёт стоимости Gemini: usageMetadata запроса (для агрегируемой статистики токенов).
            $table->unsignedInteger('prompt_tokens')->nullable()->after('rules_hash');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->unsignedInteger('total_tokens')->nullable()->after('completion_tokens');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['prompt_tokens', 'completion_tokens', 'total_tokens']);
        });
    }

    private function table(): string
    {
        return (new DocumentAnalysisResponse())->getTable();
    }
};
