<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Снос мёртвой ветки ОКПД2 листинга (D16, P2.6): `okpd2_code`/`okpd2_codes` на боевых данных
 * всегда `null`; принадлежность подмножеству гарантирует серверный фильтр профиля. Второй
 * носитель того же факта не создаётся (`findings/P1-okpd2-probe.md` §4).
 *
 * Последний измеримый исход разбора карточки хранится в `detail_parse_outcome` и перезаписывается
 * при каждом успешном снятии; история не нужна, healthcheck отбирает по `detail_fetched_at`.
 * Q16 закрыт D130: новые колонки инвентаря не добавляются, значения остаются в `attributes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('platform_id')->constrained('prc_platforms')->onDelete('NO ACTION');
            $table->unsignedBigInteger('registry_id');
            $table->foreign(['registry_id', 'platform_id'], 'prc_procurements_registry_platform_fk')
                ->references(['id', 'platform_id'])->on('prc_registries')->onDelete('NO ACTION');
            $table->text('source_identity_type')->collation('C');
            $table->text('source_identity_value')->collation('C');
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
            $table->jsonb('detail_parse_outcome')->nullable();
            $table->timestampTz('succeeded_at')->nullable();

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

            // P1.6: subject head и durable cycle allocation detail/status_refresh work
            // (execution#subject-locks). Сериализуются FOR UPDATE этой row.
            $table->bigInteger('head_generation')->default(1);
            $table->bigInteger('detail_cycle_seq')->default(0);
            $table->bigInteger('status_cycle_seq')->default(0);
            $table->timestampTz('next_status_due_at', precision: 6)->nullable();

            $table->unique(['platform_id', 'source_identity_type', 'source_identity_value'], 'prc_procurements_source_identity_unique');
            $table->index(['registry_id', 'status', 'published_at']);
            $table->index(['status', 'bidding_ends_at']);
        });

        DB::statement(
            'ALTER TABLE prc_procurements '
            ."ADD CONSTRAINT prc_procurements_source_identity_type_check CHECK (source_identity_type ~ '^[a-z][a-z0-9_.:-]{0,95}$'), "
            .'ADD CONSTRAINT prc_procurements_source_identity_value_check CHECK (char_length(source_identity_value) BETWEEN 1 AND 200), '
            .'ADD CONSTRAINT prc_procurements_head_generation_check CHECK (head_generation >= 1), '
            .'ADD CONSTRAINT prc_procurements_detail_cycle_seq_check CHECK (detail_cycle_seq >= 0), '
            .'ADD CONSTRAINT prc_procurements_status_cycle_seq_check CHECK (status_cycle_seq >= 0)',
        );

        DB::statement(
            'CREATE INDEX prc_procurements_succeeded_at_index '
            .'ON prc_procurements (succeeded_at) WHERE succeeded_at IS NOT NULL',
        );

        // 100010 создаёт prc_procurement_parties раньше родителя. После появления родителя
        // поглощаем 100048 без перенумерации create-файлов (D81).
        DB::statement(
            'ALTER TABLE prc_procurement_parties ADD CONSTRAINT prc_procurement_parties_procurement_id_foreign '
            .'FOREIGN KEY (procurement_id) REFERENCES prc_procurements (id) ON DELETE CASCADE',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE prc_procurement_parties '
            .'DROP CONSTRAINT IF EXISTS prc_procurement_parties_procurement_id_foreign',
        );
        DB::statement('DROP INDEX IF EXISTS prc_procurements_succeeded_at_index');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Procurement)->getTable();
    }
};
