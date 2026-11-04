<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Platform\Registry;
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
                ->index()
                ->constrained(table: (new Registry)->getTable())
                ->onDelete('NO ACTION'); // прежнее поведение объявлено явно.

            $table->text('registry_number');
            $table->text('reason_category')->index();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->unique(['registry_id', 'registry_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new IgnoredItem)->getTable();
    }
};
