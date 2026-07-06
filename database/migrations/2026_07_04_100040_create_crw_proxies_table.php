<?php

declare(strict_types=1);

use App\Models\Procurement\Proxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('service');
            $table->text('host');
            $table->integer('port')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->jsonb('targets')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('fails_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Proxy)->getTable();
    }
};
