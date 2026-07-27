<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('code')->unique();
            $table->text('class');
            $table->text('source_format');
            $table->text('form');
            $table->text('storage_path');
            $table->text('sha256')->unique();
            $table->bigInteger('size_bytes');
            $table->text('mime');
            $table->text('original_filename');
            $table->text('source_path')->comment('относительный путь в корпусе-источнике — провенанс');
            $table->text('generator')->nullable();
            $table->text('cluster_id')->nullable()->index();
            $table->text('doc_type')->nullable()->comment('ожидаемый вердикт скрининга, для негативов D5');
            $table->jsonb('quirks')->default('[]');
            $table->integer('expected_estimates')->nullable();
            $table->integer('expected_rows')->nullable();
            $table->text('gold_source');
            $table->boolean('is_verified')->default(false);

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            $table->timestampTz('verified_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Fixture)->getTable();
    }
};
