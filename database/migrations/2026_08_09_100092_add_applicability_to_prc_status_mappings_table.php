<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string ExactlyOneApplicabilityCheck = 'prc_status_mappings_exactly_one_applicability_check';

    private const string PlatformSourceStageUnique = 'prc_status_mappings_platform_source_stage_unique';

    private const string RegistrySourceStageUnique = 'prc_status_mappings_registry_source_stage_unique';

    private const string LegacyRegistrySourceStageUnique = 'prc_status_mappings_registry_id_source_stage_unique';

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->foreignId('platform_id')
                ->nullable()
                ->constrained(table: (new Platform)->getTable())
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE {$this->table()} DROP CONSTRAINT ".self::LegacyRegistrySourceStageUnique);
        DB::statement("ALTER TABLE {$this->table()} ALTER COLUMN registry_id DROP NOT NULL");
        DB::statement(
            "ALTER TABLE {$this->table()} ADD CONSTRAINT ".self::ExactlyOneApplicabilityCheck
            .' CHECK ((platform_id IS NOT NULL) <> (registry_id IS NOT NULL))',
        );
        DB::statement(
            'CREATE UNIQUE INDEX '.self::PlatformSourceStageUnique
            ." ON {$this->table()} (platform_id, source_stage) WHERE platform_id IS NOT NULL",
        );
        DB::statement(
            'CREATE UNIQUE INDEX '.self::RegistrySourceStageUnique
            ." ON {$this->table()} (registry_id, source_stage) WHERE registry_id IS NOT NULL",
        );
    }

    public function down(): void
    {
        if (DB::table($this->table())->whereNotNull('platform_id')->exists()) {
            throw new RuntimeException('Cannot safely roll back status mapping applicability while platform-scope rows exist.');
        }

        DB::statement('DROP INDEX IF EXISTS '.self::PlatformSourceStageUnique);
        DB::statement('DROP INDEX IF EXISTS '.self::RegistrySourceStageUnique);
        DB::statement("ALTER TABLE {$this->table()} DROP CONSTRAINT ".self::ExactlyOneApplicabilityCheck);

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_id');
        });

        DB::statement("ALTER TABLE {$this->table()} ALTER COLUMN registry_id SET NOT NULL");
        DB::statement(
            "ALTER TABLE {$this->table()} ADD CONSTRAINT ".self::LegacyRegistrySourceStageUnique
            .' UNIQUE (registry_id, source_stage)',
        );
    }

    private function table(): string
    {
        return (new StatusMapping)->getTable();
    }
};
