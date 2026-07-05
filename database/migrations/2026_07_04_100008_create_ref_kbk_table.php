<?php

declare(strict_types=1);

use App\Models\Reference\Kbk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('parent_code')->nullable()->index();
            $table->integer('level');
            $table->string('name');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Kbk)->getTable();
    }
};
