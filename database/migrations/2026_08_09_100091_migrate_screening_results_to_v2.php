<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Models\Procurement\Ai\Analysis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillActiveScreeningResults();

        DB::statement(
            "ALTER TABLE {$this->table()} ADD CONSTRAINT prc_ai_analyses_screening_result_schema_check "
            ."CHECK (step <> 'screening' OR status <> 'succeeded' OR superseded_at IS NOT NULL OR is_shadow = true OR ("
            ."result IS NOT NULL AND COALESCE(result->>'schema_version' = '2', false) "
            ."AND COALESCE(jsonb_typeof(result->'screening') = 'object', false) "
            ."AND COALESCE(jsonb_typeof(result->'decision') = 'object', false)))",
        );

        DB::statement(
            "CREATE INDEX prc_ai_analyses_active_screening_type_index ON {$this->table()} (document_type) "
            ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
            ."AND result->>'schema_version' = '2'",
        );
        DB::statement(
            "CREATE INDEX prc_ai_analyses_active_screening_tags_gin_index ON {$this->table()} "
            ."USING GIN ((result #> '{screening,tags}')) "
            ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
            ."AND result->>'schema_version' = '2'",
        );

        foreach ([
            'cardinality' => "result->'screening'->>'estimate_cardinality'",
            'disposition' => "result->'decision'->>'disposition'",
            'reason' => "result->'decision'->>'reason'",
        ] as $name => $expression) {
            DB::statement(
                "CREATE INDEX prc_ai_analyses_active_screening_{$name}_index ON {$this->table()} (({$expression})) "
                ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
                ."AND result->>'schema_version' = '2'",
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'prc_ai_analyses_active_screening_type_index',
            'prc_ai_analyses_active_screening_tags_gin_index',
            'prc_ai_analyses_active_screening_cardinality_index',
            'prc_ai_analyses_active_screening_disposition_index',
            'prc_ai_analyses_active_screening_reason_index',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement("ALTER TABLE {$this->table()} DROP CONSTRAINT IF EXISTS prc_ai_analyses_screening_result_schema_check");

        $this->restoreActiveScreeningResults();
    }

    private function backfillActiveScreeningResults(): void
    {
        foreach ($this->activeScreeningRows() as $analysis) {
            $result = $this->decodeResult($analysis->result, $analysis->id);

            if (($result['schema_version'] ?? null) === 2) {
                StoredScreeningResult::fromArray($result);

                continue;
            }

            if (! is_string($analysis->document_type)) {
                throw new RuntimeException("Analysis #{$analysis->id}: active succeeded screening has no document_type for v2 backfill.");
            }

            try {
                $stored = StoredScreeningResult::fromLegacy($analysis->document_type, $result);
            } catch (Throwable $exception) {
                throw new RuntimeException("Analysis #{$analysis->id}: screening result cannot be backfilled to v2: {$exception->getMessage()}", previous: $exception);
            }

            DB::table($this->table())
                ->where('id', $analysis->id)
                ->update(['result' => json_encode($stored->toArray(), JSON_THROW_ON_ERROR)]);
        }
    }

    private function restoreActiveScreeningResults(): void
    {
        foreach ($this->activeScreeningRows() as $analysis) {
            $result = $this->decodeResult($analysis->result, $analysis->id);

            if (($result['schema_version'] ?? null) !== 2) {
                continue;
            }

            try {
                $stored = StoredScreeningResult::fromArray($result);
            } catch (Throwable $exception) {
                throw new RuntimeException("Analysis #{$analysis->id}: screening v2 result cannot be restored to legacy form: {$exception->getMessage()}", previous: $exception);
            }

            DB::table($this->table())
                ->where('id', $analysis->id)
                ->update(['result' => json_encode($stored->toLegacyArray(), JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * @return iterable<object{id: int, document_type: ?string, result: mixed}>
     */
    private function activeScreeningRows(): iterable
    {
        return DB::table($this->table())
            ->select(['id', 'document_type', 'result'])
            ->where('step', 'screening')
            ->where('status', 'succeeded')
            ->whereNull('superseded_at')
            ->where('is_shadow', false)
            ->orderBy('id')
            ->cursor();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResult(mixed $value, int $analysisId): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException("Analysis #{$analysisId}: active succeeded screening has no JSON result for v2 backfill.");
        }

        try {
            $decoded = json_decode(json: $value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Analysis #{$analysisId}: screening result is not valid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Analysis #{$analysisId}: screening result must be a JSON object.");
        }

        return $decoded;
    }

    private function table(): string
    {
        return (new Analysis)->getTable();
    }
};
