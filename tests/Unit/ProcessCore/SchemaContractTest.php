<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkState;
use Tests\Support\ProcessCore\ContractFixtures;

test('состояния PHP совпадают с CHECK inventory и не включают события попытки', function () {
    $inventory = json_decode(file_get_contents(base_path('tests/Support/ProcessCore/schema-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
    preg_match_all("/'([^']+)'/", $inventory['carriers']['prc_process_work']['columns']['state']['definition'], $matches);

    expect(array_column(WorkState::cases(), 'value'))
        ->toBe($inventory['conventions']['work_states'])
        ->toBe($matches[1]);

    foreach (['partial', 'recovered', 'stale', 'duplicate_delivery'] as $event) {
        expect(fn () => WorkState::from($event))->toThrow(ValueError::class);
    }
})->group('t01', 'p11');

test('полный enum стадий не активирует будущую схему или handlers', function () {
    $inventory = json_decode(file_get_contents(base_path('tests/Support/ProcessCore/schema-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
    $initial = array_column($inventory['staging'], null, 'at')['P1.6'];

    expect(array_column(StageCode::cases(), 'value'))
        ->toBe($inventory['conventions']['stages'])
        ->toBe(array_keys($inventory['subjects']));
    expect($inventory['staging'][0]['supported'])->toBe([])
        ->and($inventory['staging'][0]['active'])->toBe([])
        ->and($initial['supported'])->toBe(['listing', 'detail', 'status_refresh', 'download'])
        ->and($initial['fixture_only'])->toBe($initial['supported'])
        ->and($initial['active'])->toBe([]);

    foreach (['prc_process_work', 'prc_operation_intents', 'prc_process_reservations', 'prc_process_handler_holds'] as $table) {
        preg_match_all("/'([^']+)'/", $inventory['carriers'][$table]['columns']['stage']['definition'], $matches);
        expect($matches[1])->toBe(array_column($inventory['staging'], null, 'at')['P3.13']['supported']);
    }

    // P3.13 activates the complete AI stage set on its canonical carriers.
    expect($inventory['current_item'])->toBe('P3.13')
        ->and(array_column($inventory['staging'], null, 'at')['P3.13']['active'])->toBe(array_column(StageCode::cases(), 'value'));
    foreach ($inventory['carriers'] as $table => $carrier) {
        expect(array_key_exists($table, $inventory['baseline']['tables']))->toBe(in_array($carrier['create_at'], ['P1.6', 'P1.15', 'P2.1', 'P2.16', 'P2.18', 'P3.1', 'P3.8', 'P3.9', 'P3.12'], true), $table);
    }
})->group('t01', 'p11');

test('typed subject сохраняет реальную таблицу, тип PK и момент добавления FK', function (StageCode $stage, SubjectKind $kind, string $column, string $fkAt) {
    $inventory = json_decode(file_get_contents(base_path('tests/Support/ProcessCore/schema-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
    $subject = $inventory['subjects'][$stage->value];
    $owner = $inventory['carriers']['prc_process_work']['columns'][$column];
    $id = $subject['type'] === 'bigint' ? 42 : ContractFixtures::id();
    $key = new SubjectKey($kind, $id);

    expect($subject['column'])->toBe($column)
        ->and($subject['table'])->toBe($kind->value)
        ->and($subject['fk_at'])->toBe($fkAt)
        ->and($owner['type'])->toBe($subject['type'])
        ->and($owner['references']['table'])->toBe($kind->value)
        ->and($owner['references']['columns'])->toBe(['id'])
        ->and($owner['references']['at'])->toBe($fkAt)
        ->and($key->key())->toStartWith($kind->value.':');
})->with([
    'listing' => [StageCode::Listing, SubjectKind::CrawlBlock, 'crawl_block_id', 'P1.6'],
    'detail' => [StageCode::Detail, SubjectKind::Procurement, 'procurement_id', 'P1.6'],
    'status_refresh' => [StageCode::StatusRefresh, SubjectKind::Procurement, 'procurement_id', 'P1.6'],
    'download' => [StageCode::Download, SubjectKind::Document, 'document_id', 'P1.6'],
    'prepare' => [StageCode::Prepare, SubjectKind::DocumentVersion, 'document_version_id', 'P3.1'],
    'screening' => [StageCode::Screening, SubjectKind::DocumentVersion, 'document_version_id', 'P3.1'],
    'extraction' => [StageCode::Extraction, SubjectKind::AnalysisWindow, 'analysis_window_id', 'P3.8'],
    'publication' => [StageCode::Publication, SubjectKind::AnalysisRun, 'analysis_run_id', 'P3.8'],
])->group('t01', 'p11');
