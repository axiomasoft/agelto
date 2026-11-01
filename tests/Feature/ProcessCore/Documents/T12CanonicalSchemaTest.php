<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t12', 'p31');

test('additive staging допускает legacy row, но отвергает каждый неполный canonical tuple', function (): void {
    $version = DocumentVersion::factory()->create();
    $representation = DocumentRepresentation::factory()->create(['document_version_id' => $version->id]);
    expect($version->getAttribute('blob_id'))->toBeNull()
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_document_versions')->where('id', $version->id)->update(['revision_no' => 1])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_document_representations')->where('id', $representation->id)->update(['tool_version' => 'tool/1'])))->toBe('23514')
        ->and(DB::table('prc_document_versions')->where('id', $version->id)->value('revision_no'))->toBeNull()
        ->and(DB::table('prc_document_representations')->where('id', $representation->id)->value('tool_version'))->toBeNull();
});

test('prepare screening имеют настоящий version FK, но schema support не регистрирует real handlers', function (): void {
    $version = DocumentVersion::factory()->create();
    $prepare = LedgerFixtures::storeWork(new PrepareInput($version->id, LedgerFixtures::hash('prepare')));
    $screening = LedgerFixtures::storeWork(new ScreeningInput($version->id, LedgerFixtures::hash('representation'), LedgerFixtures::hash('screening')));
    expect(DB::table('prc_process_work')->whereIn('id', [$prepare->value->value, $screening->value->value])->where('document_version_id', $version->id)->where('state', 'ready')->count())->toBe(2)
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Prepare))->toBeNull()
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Screening))->toBeNull();
});
