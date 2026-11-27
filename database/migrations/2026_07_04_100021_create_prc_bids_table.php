<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\Bid;
use App\Models\Procurement\Lot;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Protocol;
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

            $table->foreignId('procurement_id')
                ->index()
                ->constrained(table: (new Procurement)->getTable())
                ->cascadeOnDelete();

            $table->foreignId('lot_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Lot)->getTable())
                ->nullOnDelete();

            $table->foreignId('protocol_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Protocol)->getTable())
                ->nullOnDelete();

            $table->foreignId('organization_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Organization)->getTable())
                ->nullOnDelete();

            $table->text('bid_number')->nullable();
            $table->integer('participant_ordinal')->nullable();
            $table->decimal('price', total: 18, places: 2)->nullable();
            $table->text('status');
            $table->text('reject_reason')->nullable();
            $table->integer('final_rank')->nullable();

            $table->timestamps();
        });

        // Прикладной ключ upsertBid() ветвится: резолвленный участник — (procurement_id,
        // protocol_id, organization_id), анонимный — (procurement_id, protocol_id,
        // participant_ordinal). NULLS NOT DISTINCT обязателен из-за nullable protocol_id —
        // Postgres 17, Schema Builder такие индексы не умеет строить.
        DB::statement(
            "CREATE UNIQUE INDEX prc_bids_identified_participant_unique ON {$this->table()} (procurement_id, protocol_id, organization_id) NULLS NOT DISTINCT WHERE organization_id IS NOT NULL",
        );

        DB::statement(
            "CREATE UNIQUE INDEX prc_bids_anonymous_participant_unique ON {$this->table()} (procurement_id, protocol_id, participant_ordinal) NULLS NOT DISTINCT WHERE organization_id IS NULL",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Bid)->getTable();
    }
};
