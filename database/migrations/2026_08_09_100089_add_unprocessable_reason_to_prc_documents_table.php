<?php

declare(strict_types=1);

use App\Models\Procurement\Document\Document;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('unprocessable_reason', 64)->nullable()->index()->after('last_error');
        });

        foreach (['too_large', 'zip_bomb', 'unreadable_format', 'password_protected', 'provider_limit_size', 'provider_limit_pages', 'conversion_failed', 'ocr_failed', 'archive_unsupported'] as $reason) {
            DB::table($this->table())
                ->where('last_error', 'like', "unprocessable:{$reason}:%")
                ->update(['unprocessable_reason' => $reason]);
        }
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('unprocessable_reason');
        });
    }

    private function table(): string
    {
        return (new Document)->getTable();
    }
};
