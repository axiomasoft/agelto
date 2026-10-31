<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Services\Procurement\Acquisition\AcquisitionArtifacts;
use App\Services\Procurement\Acquisition\AcquisitionAssessment;
use App\Services\Procurement\Acquisition\AcquisitionSources;
use App\Services\Procurement\Acquisition\DetailArtifacts;
use App\Services\Procurement\Acquisition\DetailHandler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Platforms\Eis\EisDetailFixtures as D;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(function (): void {
    F::bind();
    Storage::fake('s3');
});

function detailProcessAttempt(array $f, array $bodies): StageOutcome
{
    $outbound = D::outbound($bodies);
    $assessment = new AcquisitionAssessment(function ($rejection) use ($bodies) {
        foreach ($bodies as $body) {
            if (hash('sha256', $body) === $rejection->assessment->bodyHash->hex) {
                $artifact = app(AcquisitionArtifacts::class)->rejected(
                    LedgerFixtures::uuid(), new DecodedTextBody($body, 'UTF-8'));
                $a = $rejection->assessment;

                return new FetchItemRejected($rejection->reason,
                    new ResponseAssessment($a->completeness, $a->reason, $a->bodyHash, $a->confidence, $artifact));
            }
        }
        throw new LogicException('No matching assessed fixture bytes.');
    });
    $source = AcquisitionSources::detail(D::source($outbound), $assessment);
    $handler = new DetailHandler(app(DetailSnapshotRepository::class), $source, app(DetailArtifacts::class), new FetchOutcomeBridge);
    $lease = E::work($f['work'])->state === 'deferred' ? FinalizeFixtures::rerun($f['work']) : FinalizeFixtures::running($f['work']);
    $context = app(ExecutionContextFactory::class)->open($lease);
    $outcome = $handler->execute($f['input'], $context);
    app(WorkFinalizer::class)->finish($context->lease(), $outcome);

    return $outcome;
}

function detailCompleteBodies(): array
{
    return [...array_values(D::completeBodies()), D::bodies()['common-info']];
}

test('Process complete EIS detail publishes all tab projections and pending documents without inline downloads', function (): void {
    Queue::fake();
    $f = F::work(value: '0338200009125000001');
    expect(detailProcessAttempt($f, detailCompleteBodies()))->toBeInstanceOf(StageSucceeded::class)
        ->and(E::work($f['work'])->state)->toBe('succeeded')
        ->and(EisDetails::query()->where('procurement_id', $f['p']->id)->count())->toBe(1)
        ->and(ProcurementParty::query()->where('procurement_id', $f['p']->id)->count())->toBe(2)
        ->and(Protocol::query()->where('procurement_id', $f['p']->id)->count())->toBeGreaterThan(0)
        ->and(Document::query()->where('procurement_id', $f['p']->id)->count())->toBe(6)
        ->and(Document::query()->where('procurement_id', $f['p']->id)->whereNotNull('source_url')->count())->toBe(0)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->where('state', 'pending')->count())->toBe(6)
        ->and(DB::table('prc_detail_snapshots')->where('procurement_id', $f['p']->id)->where('part_key', 'like', '%:published')->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('Process EIS incomplete supplier results resume saved parts and publish only the coherent later outcome', function (): void {
    $f = F::work(value: '0338200009125000001');
    $bad = array_values(D::completeBodies());
    $bad[3] = '<html>temporary protection page</html>';
    expect(detailProcessAttempt($f, $bad))->toBeInstanceOf(StagePartial::class)
        ->and(E::work($f['work'])->item_failures)->toBe(0)
        ->and(Document::query()->count())->toBe(0)->and(EisDetails::query()->count())->toBe(0);
    expect(detailProcessAttempt($f, [D::completeBodies()['supplier-results'], D::bodies()['common-info']]))->toBeInstanceOf(StageSucceeded::class)
        ->and(E::work($f['work'])->state)->toBe('succeeded')->and(Protocol::query()->count())->toBeGreaterThan(0)
        ->and(Document::query()->count())->toBe(6);
});

test('Process EIS incomplete document refresh preserves all previously published attachment links', function (): void {
    $f = F::work(value: '0338200009125000001');
    detailProcessAttempt($f, detailCompleteBodies());
    $before = Document::query()->where('procurement_id', $f['p']->id)->orderBy('id')->pluck('id')->all();
    DB::table('prc_operation_intents')->where('id', $f['input']->intentId->value)->update(['next_due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
    $work = app(RefreshRepository::class)->scheduleDetail($f['p']->id);
    expect($work)->not->toBeNull();
    $f['work'] = $work;
    $f['input'] = F::input($work);
    expect(detailProcessAttempt($f, [D::bodies()['common-info'], '<html>unknown documents</html>']))->toBeInstanceOf(StagePartial::class)
        ->and(Document::query()->where('procurement_id', $f['p']->id)->whereNull('removed_at')->orderBy('id')->pluck('id')->all())->toBe($before)
        ->and(E::work($work)->item_failures)->toBe(0);
});

test('Process EIS explicit complete empty document container finishes detail instead of retrying forever', function (): void {
    $f = F::work(value: '0338200009125000001');
    $bodies = detailCompleteBodies();
    $bodies[1] = '<a class="cardMainInfo__purchaseLink">№ 0338200009125000001</a><div class="blockFilesTabDocs"></div>';
    expect(detailProcessAttempt($f, $bodies))->toBeInstanceOf(StageSucceeded::class)
        ->and(E::work($f['work'])->state)->toBe('succeeded')->and(Document::query()->count())->toBe(0)
        ->and(E::work($f['work'])->item_failures)->toBe(0);
});

test('Process EIS missing parties remain incomplete until a complete common info page is revalidated', function (): void {
    $f = F::work(value: '0338200009125000001');
    $common = D::bodies()['common-info'].'<section class="organizations-block"><table class="parties-table"><tbody><tr><td>unknown role</td></tr></tbody></table></section>';
    expect(detailProcessAttempt($f, [$common]))->toBeInstanceOf(StagePartial::class)
        ->and(ProcurementParty::query()->count())->toBe(0)->and(E::work($f['work'])->item_failures)->toBe(0);
    expect(detailProcessAttempt($f, detailCompleteBodies()))->toBeInstanceOf(StageSucceeded::class)
        ->and(ProcurementParty::query()->count())->toBe(2);
});

test('Process EIS wrong card identity rejects only its work and never creates platform detail rows', function (): void {
    $f = F::work(value: '0338200009125000001');
    $other = F::work(registry: $f['p']->registry, value: '0338200009125000002');
    $common = str_replace('0338200009125000001', '9999999999999999999', D::bodies()['common-info']);
    expect(detailProcessAttempt($f, [$common]))->toBeInstanceOf(StageFailedItem::class)
        ->and(E::work($f['work'])->state)->toBe('deferred')->and(E::work($f['work'])->item_failures)->toBe(1)
        ->and(EisDetails::query()->count())->toBe(0)->and(Document::query()->count())->toBe(0)
        ->and(E::work($other['work'])->state)->toBe('admitted')->and(E::work($other['work'])->execution_attempts)->toBe(0)
        ->and(E::work($other['work'])->item_failures)->toBe(0);
});
