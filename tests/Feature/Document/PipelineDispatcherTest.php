<?php

declare(strict_types=1);

use App\Services\Procurement\Document\DocumentFeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;

test('document producer не дублирует ready work и не имеет ordinary dispatch bridge', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    new PrepareFixtures;
    Queue::fake();
    expect(app(DocumentFeeder::class)->reconcile())->toBe(0)->and(app(DocumentFeeder::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_process_work')->where('stage', 'prepare')->count())->toBe(1)
        ->and(DB::table('prc_admission_groups')->count())->toBe(0)
        ->and(is_file(app_path('Services/Procurement/PipelineDispatcher.php')))->toBeFalse()
        ->and(is_file(app_path('Jobs/Procurement/PrepareDocumentJob.php')))->toBeFalse();
    Queue::assertNothingPushed();
});
