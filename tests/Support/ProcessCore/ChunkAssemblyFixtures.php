<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\Execution\AnalysisRecipe;
use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Extraction\ValidatedChunk;
use App\Dto\Procurement\Ai\Output\ExtractionOutput;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\Pipeline\ExtractionSemanticValidator;

final class ChunkAssemblyFixtures
{
    public static function run(?AnalysisRecipe $recipe = null): AnalysisRunSnapshot
    {
        return new AnalysisRunSnapshot(LedgerFixtures::uuid(), 1, 1, ContentHash::of('anchored source'),
            $recipe ?? AiFixtures::recipe(), 1, 1, 2, 'fixture');
    }

    /** @return array<string,int> */
    public static function anchors(int $count): array
    {
        $anchors = [];
        for ($i = 0; $i < $count; $i++) {
            $anchors['p0:r'.$i] = 0;
        }

        return $anchors;
    }

    public static function chunk(AnalysisWindowSnapshot $window, ?RowRange $range = null, ?string $name = null, ?string $total = null): ValidatedChunk
    {
        $range ??= $window->range;
        $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
        $template = $raw['rows'][0];
        $raw['rows'] = [];
        for ($i = $range->start; $i < $range->end; $i++) {
            $row = $template;
            $row['row']['row_index'] = $i;
            $row['estimate_ordinal'] = $window->estimateOrdinal;
            $row['source_anchor'] = 'p0:r'.$i;
            $row['row']['name'] = $name ?? 'Позиция '.$i;
            $raw['rows'][] = $row;
        }
        foreach ($raw['totals'] as &$node) {
            $node['estimate_ordinal'] = $window->estimateOrdinal;
            if ($node['node_id'] === 'n0' && $total !== null) {
                $node['value'] = $total;
            }
        }
        unset($node);
        $task = new StructuredTask(ExtractionOutput::class, $window->run->recipe->tasks->task(TaskPurpose::Rows)->prompt,
            TaskPurpose::Rows, new ExtractionSemanticValidator($range, $window->estimateOrdinal, $window->sourceAnchors));
        $result = app(StructuredTaskValidator::class)->validate($task, CanonicalJson::encode($raw), 'STOP');
        $hash = ContentHash::of(CanonicalJson::encode($result->data?->toArray()));

        return new ValidatedChunk(LedgerFixtures::uuid(), $window, $range, 0, new ArtifactRef('local', 'fixture/'.$hash->hex, $hash), $result);
    }
}
