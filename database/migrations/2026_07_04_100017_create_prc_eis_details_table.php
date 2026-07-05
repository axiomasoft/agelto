<?php

declare(strict_types=1);

use App\Models\Procurement\EisDetails;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            // Extension 1:1 — procurement_id одновременно PK и FK, без собственного id.
            $table->foreignId('procurement_id')
                ->primary()
                ->constrained(table: (new Procurement)->getTable());

            // ИКЗ (36 разрядов) — разбор в колонки, без парсинга строки в коде.
            $table->integer('ikz_year')->nullable();
            $table->text('ikz_customer_code')->nullable();
            $table->text('ikz_plan_number')->nullable();
            $table->text('ikz_plangraph_number')->nullable();
            $table->text('ikz_okpd_class')->nullable();
            $table->text('ikz_okpd_subclass')->nullable();
            $table->text('ikz_okpd_group')->nullable();
            $table->text('ikz_kvr')->nullable();

            $table->text('plan_graph_position')->nullable();
            $table->text('plan_graph_url')->nullable();

            $table->boolean('bid_security_required')->default(false);
            $table->decimal('bid_security_percent', total: 5, places: 2)->nullable();
            $table->decimal('bid_security_amount', total: 18, places: 2)->nullable();
            $table->boolean('performance_security_required')->default(false);
            $table->decimal('performance_security_percent', total: 5, places: 2)->nullable();
            $table->decimal('performance_security_amount', total: 18, places: 2)->nullable();

            $table->boolean('is_smp_only')->default(false);
            $table->boolean('anti_dumping_applied')->default(false);
            $table->boolean('treasury_support')->default(false);
            $table->jsonb('advantages')->nullable();
            $table->jsonb('national_regime')->nullable();
            $table->jsonb('participant_requirements')->nullable();

            $table->text('etp_name')->nullable();
            $table->text('etp_url')->nullable();

            // Текст, не timestamptz — может быть «с даты заключения контракта».
            $table->text('execution_starts_at')->nullable();
            $table->text('execution_ends_at')->nullable();

            $table->jsonb('contact_person')->nullable();
            $table->jsonb('print_form_urls')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new EisDetails)->getTable();
    }
};
