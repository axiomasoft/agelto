<?php

declare(strict_types=1);

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Platform;
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

            $table->foreignId('procurement_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Procurement)->getTable());

            $table->foreignId('platform_id')
                ->index()
                ->constrained(table: (new Platform)->getTable());

            // uid вложения в filestore ЕИС.
            $table->text('external_id')->nullable();

            $table->text('title');
            $table->text('kind')->nullable()->comment('извещение/протокол/контракт/… — свободный текст источника');
            $table->text('source_url')->nullable();
            $table->text('status');

            // Партиционированная таблица — FK не строим, только ссылка.
            $table->unsignedBigInteger('first_seen_revision_id')->nullable();

            $table->timestampTz('removed_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);

            $table->timestamps();
        });

        // Partial unique index: external_id уникален в рамках закупки только когда не null.
        DB::statement(
            'CREATE UNIQUE INDEX prc_documents_procurement_external_id_unique '
            .'ON prc_documents (procurement_id, external_id) WHERE external_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_documents_procurement_external_id_unique');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Document)->getTable();
    }
};
