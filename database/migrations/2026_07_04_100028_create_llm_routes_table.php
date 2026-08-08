<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7.5 F-02/D133: маршрут сохраняет исходный `NO ACTION` к провайдеру явно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('document_type')->comment('ключ маршрутизации: значение AiDocumentType либо сентинел any (кросс-документный маршрут), целостность — check-constraint (D16/D17)');
            $table->text('purpose')->comment('совпадает с llm_calls.purpose');

            $table->foreignId('provider_id')
                ->index()
                ->constrained(table: (new Provider)->getTable())
                ->onDelete('NO ACTION');

            $table->text('model');
            $table->jsonb('options')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('shadow')->default(false)->comment('теневой прогон, не значение purpose');

            $table->timestamps();

            $table->unique(['document_type', 'purpose', 'priority', 'shadow']);
        });

        DB::statement(
            'ALTER TABLE llm_routes '
            .'ADD CONSTRAINT llm_routes_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeOrSentinelValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new LlmRoute)->getTable();
    }

    private function documentTypeOrSentinelValues(): string
    {
        return collect([...AiDocumentType::values(), DocumentTypeScope::Any->value])
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }
};
