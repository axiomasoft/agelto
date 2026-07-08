<?php

declare(strict_types=1);

use App\Models\Procurement\Document\Document;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('locked_by')->nullable()->after('failed_permanently');
            $table->timestampTz('locked_until')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['locked_by', 'locked_until']);
        });
    }

    private function table(): string
    {
        return (new Document)->getTable();
    }
};
