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

pest()->group('document-storage-and-references');

test('требует canonical tuple и bytea hash без legacy partial indexes', function (): void {
    $version = DocumentVersion::factory()->create();
    $representation = DocumentRepresentation::factory()->create(['document_version_id' => $version->id]);
    expect($version->getAttribute('blob_id'))->not->toBeNull()
        ->and($version->disk)->not->toBeEmpty()
        ->and($representation->getAttribute('blob_id'))->not->toBeNull();
    foreach (['revision_no', 'blob_id', 'first_seen_at', 'disk'] as $column) {
        expect(LedgerFixtures::sqlState(fn () => DB::table('prc_document_versions')->where('id', $version->id)->update([$column => null])))->toBeIn(['23502', '23514']);
    }
    foreach (['blob_id', 'preparation_recipe_hash', 'content_hash', 'range_key', 'tool_version', 'coverage_manifest', 'coverage_manifest_schema_version', 'disk', 'size_bytes'] as $column) {
        expect(LedgerFixtures::sqlState(fn () => DB::table('prc_document_representations')->where('id', $representation->id)->update([$column => null])))->toBeIn(['23502', '23514']);
    }
    expect(DB::selectOne('SELECT pg_typeof(content_hash)::text AS type,octet_length(content_hash) AS length FROM prc_document_versions WHERE id=?', [$version->id]))->toMatchObject(['type' => 'bytea', 'length' => 32])
        ->and(DB::table('pg_indexes')->whereIn('indexname', ['prc_document_versions_legacy_hash_unique', 'prc_document_representations_legacy_kind_unique'])->count())->toBe(0);
});

test('prepare screening имеют настоящий version FK, но schema support не регистрирует real handlers', function (): void {
    $version = DocumentVersion::factory()->create();
    $prepare = LedgerFixtures::storeWork(new PrepareInput($version->id, LedgerFixtures::hash('prepare')));
    $screening = LedgerFixtures::storeWork(new ScreeningInput($version->id, LedgerFixtures::hash('representation'), LedgerFixtures::hash('screening')));
    expect(DB::table('prc_process_work')->whereIn('id', [$prepare->value->value, $screening->value->value])->where('document_version_id', $version->id)->where('state', 'ready')->count())->toBe(2)
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Prepare))->toBeNull()
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Screening))->toBeNull();
});
