<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\PublicationInput;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Services\Procurement\Extraction\Orchestration\ExtractionHandler;
use App\Services\Procurement\Extraction\Publication\PublicationHandler;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Support\Database\PostgresBulkInserter;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

test('публикация сохраняет дерево сметы пакетами с точными строками и происхождением', function (): void {
    $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $rows = [];
    $special = "Смета \"Тест\" \\ путь\nстрока";
    for ($index = 0; $index < 19; $index++) {
        $type = match ($index) {
            0, 10 => 'section_header',
            1, 11 => 'subsection_header',
            default => 'item',
        };
        $row = ['row_index' => $index, 'row_type' => $type, 'name' => $type === 'item' ? "{$special} {$index}" : "{$type} {$special} {$index}"];
        if ($type === 'item') {
            $row += ['code' => "Код \\ \"{$index}\"", 'unit' => '100 м2', 'quantity' => '1',
                'unit_price_curr' => '9007199254740993.010000000001', 'total_price_curr' => '9007199254740993.010000000001',
                'source_position_number' => "{$index}.1", 'work_type_name' => $index % 2 === 0 ? 'Монтаж "А"' : 'Проверка \\ Б',
                'resources' => [
                    ['resource_type' => 'material', 'name' => "Ресурс {$special} {$index}.1", 'unit' => '100 м2',
                        'quantity' => '1', 'price_curr' => '123.000000000001'],
                    ['resource_type' => 'labor', 'name' => "Ресурс {$special} {$index}.2", 'quantity' => null],
                ]];
            if ($index === 2) {
                $row['attributes'] = ['amount_since_start' => '1.5'];
            }
        }
        $rows[] = ['estimate_ordinal' => 1, 'source_anchor' => 'p0:r'.$index, 'page_index' => 0, 'row' => $row];
    }
    $raw['rows'] = $rows;
    $raw['totals'][0]['label'] = $special;

    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work(new RowRange(0, 19));
    ExtractionRuntimeFixtures::capture($lease, $window, $period, json_encode($raw, JSON_THROW_ON_ERROR));
    $extractionInput = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($extractionInput, app(ExecutionContextFactory::class)->open($lease)));
        if ($attempt === 0) {
            $lease = FinalizeFixtures::running($work);
        }
    }
    $publication = new WorkId(new ProcessUuid((string) DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
    $lease = FinalizeFixtures::running($publication);
    $input = new PublicationInput($window->run->id, $window->run->recipe->hash, $window->run->generation);
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $result = app(WorkFinalizer::class)->finish($lease, $outcome);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    expect($result->state?->value)->toBe('succeeded');
    $insertCounts = [];
    foreach ($queries as $query) {
        if (preg_match('/insert into "?(prc_estimate_sections|prc_estimate_work_types|prc_estimate_items|prc_estimate_resources|prc_estimate_totals)"?/i', $query['query'], $match) === 1) {
            $insertCounts[$match[1]] = ($insertCounts[$match[1]] ?? 0) + 1;
            expect(strtolower($query['query']))->toContain('unnest(');
        }
    }
    expect($insertCounts)->toBe(['prc_estimate_sections' => 2, 'prc_estimate_work_types' => 1,
        'prc_estimate_items' => 1, 'prc_estimate_resources' => 1, 'prc_estimate_totals' => 1]);

    $estimateId = DB::table('prc_estimates')->soleValue('id');
    $sections = DB::table('prc_estimate_sections')->orderBy('position')->get();
    expect($sections)->toHaveCount(4);
    foreach ([0, 1, 10, 11] as $offset => $position) {
        $section = $sections[$offset];
        expect($section->position)->toBe($position)
            ->and($section->estimate_id)->toBe($estimateId)
            ->and($section->title)->toBe(($position === 0 || $position === 10 ? 'section_header ' : 'subsection_header ')."{$special} {$position}")
            ->and($section->parent_id)->toBe($position === 1 ? $sections[0]->id : ($position === 11 ? $sections[2]->id : null))
            ->and(json_decode($section->analysis_provenance, true, flags: JSON_THROW_ON_ERROR)['source_anchor'])->toBe('p0:r'.$position)
            ->and($section->analysis_provenance_schema_version)->toBe(1)
            ->and($section->created_at)->not->toBeNull()
            ->and($section->updated_at)->not->toBeNull();
    }

    $workTypes = DB::table('prc_estimate_work_types')->orderBy('position')->get();
    expect($workTypes->pluck('position')->all())->toBe([2, 3]);
    expect($workTypes->pluck('title')->all())->toBe(['Монтаж "А"', 'Проверка \\ Б']);
    foreach ($workTypes as $workType) {
        expect($workType->estimate_id)->toBe($estimateId)
            ->and($workType->created_at)->not->toBeNull()->and($workType->updated_at)->not->toBeNull();
    }

    $items = DB::table('prc_estimate_items')->orderBy('row_index')->get();
    expect($items)->toHaveCount(15);
    foreach ($items as $item) {
        $position = $item->row_index;
        expect($item->name)->toBe("{$special} {$position}")
            ->and($item->estimate_id)->toBe($estimateId)
            ->and($item->code)->toBe("Код \\ \"{$position}\"")
            ->and($item->section_id)->toBe($position < 10 ? $sections[1]->id : $sections[3]->id)
            ->and($item->work_type_id)->toBe($position % 2 === 0 ? $workTypes[0]->id : $workTypes[1]->id)
            ->and($item->unit)->toBe('100 м2')->and($item->unit_base)->toBe('м2')->and($item->unit_multiplier)->toBe('100')
            ->and($item->quantity)->toBe('1')->and($item->unit_price_curr)->toBe('9007199254740993.010000000001')
            ->and($item->total_price_curr)->toBe('9007199254740993.010000000001')
            ->and($item->unit_price_base)->toBeNull()->and($item->total_price_base)->toBeNull()
            ->and($item->index_code)->toBeNull()->and($item->index_value)->toBeNull()
            ->and($item->source_position_number)->toBe("{$position}.1")
            ->and($item->quality)->toBeNull()
            ->and($item->analysis_provenance_schema_version)->toBe(1)
            ->and(json_decode($item->analysis_provenance, true, flags: JSON_THROW_ON_ERROR)['source_anchor'])->toBe('p0:r'.$position)
            ->and($item->created_at)->not->toBeNull()->and($item->updated_at)->not->toBeNull();
        expect($item->attributes === null ? null : json_decode($item->attributes, true, flags: JSON_THROW_ON_ERROR))
            ->toBe($position === 2 ? ['amount_since_start' => '1.5'] : null);
    }

    $resources = DB::table('prc_estimate_resources AS r')->join('prc_estimate_items AS i', 'i.id', '=', 'r.item_id')
        ->orderBy('i.row_index')->orderBy('r.id')->select('r.*', 'i.row_index')->get();
    expect($resources)->toHaveCount(30);
    foreach ($resources as $offset => $resource) {
        $position = $resource->row_index;
        $ordinal = $offset % 2 + 1;
        expect($resource->name)->toBe("Ресурс {$special} {$position}.{$ordinal}")
            ->and($resource->resource_type)->toBe($ordinal === 1 ? 'material' : 'labor')
            ->and($resource->unit)->toBe($ordinal === 1 ? '100 м2' : null)
            ->and($resource->unit_base)->toBe($ordinal === 1 ? 'м2' : null)
            ->and($resource->unit_multiplier)->toBe($ordinal === 1 ? '100' : '1')
            ->and($resource->quantity)->toBe($ordinal === 1 ? '1' : null)
            ->and($resource->price_curr)->toBe($ordinal === 1 ? '123.000000000001' : null)
            ->and($resource->price_base)->toBeNull()->and($resource->code)->toBeNull()
            ->and($resource->index_code)->toBeNull()->and($resource->index_value)->toBeNull()
            ->and($resource->analysis_provenance_schema_version)->toBe(1)
            ->and(json_decode($resource->analysis_provenance, true, flags: JSON_THROW_ON_ERROR)['source_anchor'])->toBe('p0:r'.$position)
            ->and($resource->created_at)->not->toBeNull()->and($resource->updated_at)->not->toBeNull();
    }

    $totals = DB::table('prc_estimate_totals')->orderBy('position')->get();
    expect($totals)->toHaveCount(6);
    foreach ($totals as $position => $total) {
        expect($total->source_node_id)->toBe('n'.$position)
            ->and($total->estimate_id)->toBe($estimateId)->and($total->position)->toBe($position)
            ->and($total->source_parent_node_id)->toBe($position === 0 ? null : 'n'.($position - 1))
            ->and($total->parent_id)->toBe($position === 0 ? null : $totals[$position - 1]->id)
            ->and($total->label)->toBe($position === 0 ? $special : 'Уровень '.$position)
            ->and($total->amount)->toBe($position === 0 ? '9007199254740993.01' : null)
            ->and($total->analysis_provenance_schema_version)->toBe(1)
            ->and(json_decode($total->analysis_provenance, true, flags: JSON_THROW_ON_ERROR)['source_anchor'])->toBe('p0:r0')
            ->and($total->created_at)->not->toBeNull()->and($total->updated_at)->not->toBeNull();
    }
});

test('массивы PostgreSQL сохраняют спецсимволы и отвергают неверные числа', function (): void {
    $special = "Кириллица \"кавычки\" \\ путь\nстрока";
    $json = json_encode(['text' => $special, 'nul' => "a\0b"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $jsonLiteral = PostgresBulkInserter::pgJsonbArray([$json, null]);
    $decoded = DB::select('SELECT value FROM UNNEST(?::jsonb[]) AS v(value)', [$jsonLiteral]);
    expect(json_decode($decoded[0]->value, true, flags: JSON_THROW_ON_ERROR))->toBe(['nul' => 'ab', 'text' => $special])
        ->and($decoded[1]->value)->toBeNull();

    $textLiteral = PostgresBulkInserter::pgTextArray([$special, "a\0b", null]);
    $strings = DB::select('SELECT value FROM UNNEST(?::text[]) AS v(value)', [$textLiteral]);
    expect(array_map(static fn (object $row): ?string => $row->value, $strings))->toBe([$special, 'ab', null]);

    expect(fn () => PostgresBulkInserter::pgNumericArray(['1e3']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => PostgresBulkInserter::pgIntArray(['1']))
        ->toThrow(InvalidArgumentException::class);
});
