<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Process\RecoveryReconciler;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DocumentFeederFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

pest()->group('document-processing', 'admission-fairness');
beforeEach(fn () => StoredDocumentFixtures::reset());

test('document producer LIMIT50 стоит на входной выборке и terminal history не заслоняет missing prepare', function (): void {
    DocumentVersion::factory()->count(51)->create();
    expect(app(DocumentFeeder::class)->reconcile())->toBe(50)
        ->and(DB::table('prc_process_work')->count())->toBe(50)
        ->and(app(DocumentFeeder::class)->reconcile())->toBe(1)
        ->and(app(DocumentFeeder::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(51)
        ->and(DB::table('prc_process_runs')->count())->toBe(51);
});

test('document без source reference и bytes не создаёт intents work или outbound effects', function (): void {
    Document::factory()->create(['source_url' => null]);
    expect(app(DocumentFeeder::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_operation_intents')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('общий recovery после expired document attempt освобождает parent child без нового logical work', function (): void {
    FinalizeFixtures::authority([StageCode::Screening]);
    $work = DocumentFeederFixtures::analysis('manual');
    $lease = FinalizeFixtures::running($work);
    FinalizeFixtures::expire($work);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0)
        ->and(DB::table('prc_process_work')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('generation'))->toBeGreaterThan($lease->generation)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0);
});
