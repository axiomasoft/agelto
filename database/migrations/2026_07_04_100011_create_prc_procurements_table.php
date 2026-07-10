<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('registry_id')
                ->constrained(table: (new Registry)->getTable());

            $table->text('registry_number');
            $table->text('etp_number')->nullable();
            $table->text('ikz')->nullable()->index();
            $table->text('title');
            $table->text('status');
            $table->text('source_stage');
            $table->text('procedure_type')->nullable();
            $table->text('procedure_type_raw')->nullable();

            $table->foreignId('customer_org_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Organization)->getTable())
                ->nullOnDelete();

            $table->decimal('initial_price', total: 18, places: 2)->nullable();
            $table->char('currency', length: 3)->nullable();
            $table->decimal('advance_percent', total: 5, places: 2)->nullable();

            $table->text('okpd2_code')->nullable()->index();
            $table->text('okved_code')->nullable()->index();
            $table->text('okved_additional')->nullable()->index();
            $table->text('region_code')->nullable()->index();

            $table->timestampTz('published_at')->index();
            $table->timestampTz('source_updated_at')->index();
            $table->timestampTz('bidding_starts_at')->nullable();
            $table->timestampTz('bidding_ends_at')->nullable();
            $table->timestampTz('summary_at')->nullable();

            $table->text('detail_url')->nullable();
            $table->timestampTz('detail_fetched_at')->nullable();
            $table->integer('detail_attempts')->default(0);
            $table->text('detail_last_error')->nullable();
            $table->timestampTz('detail_next_retry_at')->nullable();
            $table->boolean('detail_failed')->default(false);

            $table->text('registry_guid')->nullable()->index();

            // Без constrained() — prc_revisions партиционирована, FK на неё не строится.
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestampTz('current_revision_detected_at')->nullable();
            $table->integer('revision_count')->default(1);

            $table->timestampTz('ignored_at')->nullable();
            $table->text('ignore_reason')->nullable();

            $table->jsonb('attributes')->nullable();

            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->timestamps();

            $table->unique(['registry_id', 'registry_number']);
            $table->index(['registry_id', 'status', 'published_at']);
            $table->index(['status', 'bidding_ends_at']);
        });

        DB::statement('ALTER TABLE prc_procurements ADD COLUMN okpd2_codes text[]');
        DB::statement('CREATE INDEX prc_procurements_okpd2_codes_gin ON prc_procurements USING GIN (okpd2_codes)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_procurements_okpd2_codes_gin');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Procurement)->getTable();
    }
};
