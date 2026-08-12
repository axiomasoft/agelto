<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('archive_entry_path')->nullable()->after('parent_version_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX prc_document_versions_parent_archive_entry_path_unique '
            .'ON prc_document_versions (parent_version_id, archive_entry_path) '
            .'WHERE parent_version_id IS NOT NULL AND archive_entry_path IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_document_versions_parent_archive_entry_path_unique');

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('archive_entry_path');
        });
    }

    private function table(): string
    {
        return (new DocumentVersion)->getTable();
    }
};
