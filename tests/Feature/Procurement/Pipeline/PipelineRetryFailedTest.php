<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DocumentFeederFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;

test('document retry принадлежит durable due и не имеет отдельной retry команды', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    FinalizeFixtures::authority([StageCode::Screening]);
    $work = DocumentFeederFixtures::analysis('manual');
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, new StageDeferred(DeferralReason::ProviderUnavailable, new RetryAt(new DateTimeImmutable('+1 hour'))));
    expect(app(WorkFeeder::class)->tick('analysis'))->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('deferred')
        ->and(is_file(app_path('Console/Commands/Procurement/PipelineRetryFailed.php')))->toBeFalse();
});

// Проверяет маршрутизацию повторной обработки.
// безусловно («стадия ещё не реализована») — провалившийся extraction никогда не
// передиспатчился, хотя EstimateExtractionJob существует с e06434c.
