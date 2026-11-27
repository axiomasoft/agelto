<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;

pest()->group('admission-fairness');

beforeEach(fn () => ExecutionFixtures::truncateLedger());
afterEach(fn () => ExecutionFixtures::truncateLedger());

function fairnessAdmissionWaiter(WorkId $work, string $cursor): string
{
    return sprintf(
        '\\Tests\\Support\\ProcessCore\\ExecutionFixtures::registerHandler(\\App\Enums\Procurement\Process\Core\StageCode::Listing);'
        .'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () {'
        .'$cursor = app(\\App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec::class)->decodeFairnessCursor('
        .'new \\App\Dto\Procurement\Process\Primitives\VersionedPayload(base64_decode(\'%s\', true), 1));'
        .'$result = app(\\App\Repositories\Procurement\Process\Work\AdmissionRepository::class)->admit('
        .'new \\App\Dto\Procurement\Process\Primitives\WorkId(new \\App\Dto\Procurement\Process\Primitives\ProcessUuid(\'%s\')), $cursor, 1);'
        ."return ['status' => \$result->status->value, 'pid' => \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];"
        .'});',
        base64_encode($cursor),
        $work->value->value,
    );
}

test('real PG: ready/resume commit между selection и capacity grant требует нового выбора', function (bool $resume): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 4, 'max_running' => 4, 'low_watermark' => 3, 'refill_max' => 1],
        'acquisition.listing' => ['max_outstanding' => 4, 'max_running' => 2, 'low_watermark' => 3, 'refill_max' => 1],
    ]);
    $first = array_map(fn (): WorkId => ExecutionFixtures::listingWork('platform:aaa'), range(1, 3));
    // Each tick has one grant; keep the original two A admissions before B becomes eligible.
    expect(app(WorkFeeder::class)->tick('acquisition') + app(WorkFeeder::class)->tick('acquisition'))->toBe(2);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'platform:bbb');
    $second = ExecutionFixtures::listingWork('platform:bbb');
    if ($resume) {
        ExecutionFixtures::setMode('acquisition:platform:bbb', ControlMode::Paused);
    } else {
        DB::update("UPDATE prc_process_work SET state = 'deferred', reason_code = 'rate_limit',
                    due_at = clock_timestamp() + interval '1 hour' WHERE id = ?", [$second->value->value]);
    }
    $cursor = (string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor');
    $before = (array) DB::table('prc_process_work')->where('id', $first[2]->value->value)
        ->selectRaw("*, encode(work_key, 'hex') AS work_key, encode(input_hash, 'hex') AS input_hash")->sole();
    $holder = $resume
        ? '\\Tests\\Support\\ProcessCore\\ExecutionFixtures::setMode(\'acquisition:platform:bbb\', \\App\Enums\Procurement\Process\Governance\ControlMode::Enabled);'
        : '';
    $holder .= '\\Illuminate\\Support\\Facades\\DB::select("SELECT id FROM prc_process_lanes'
        .' WHERE lane_key IN (\'acquisition\', \'acquisition.listing\') ORDER BY id FOR UPDATE");';
    if (! $resume) {
        $holder .= sprintf(
            '\\Illuminate\\Support\\Facades\\DB::update("UPDATE prc_process_work SET state = \'ready\', reason_code = NULL, due_at = NULL WHERE id = ?", [\'%s\']);',
            $second->value->value,
        );
    }
    $holder .= "return ['pid' => \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];";
    // holder has an uncommitted eligibility change; it commits only after admit really waits.
    $holder = 'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () { '.$holder.' });';
    $waiter = fairnessAdmissionWaiter($first[2], $cursor);
    $results = ConcurrentTransactions::concurrently(['eligibility' => $holder, 'admit' => $waiter]);

    expect($results['admit']['status'])->toBe('cursor_moved')
        ->and($results['admit']['pid'])->not->toBe($results['eligibility']['pid'])
        ->and((array) DB::table('prc_process_work')->where('id', $first[2]->value->value)
            ->selectRaw("*, encode(work_key, 'hex') AS work_key, encode(input_hash, 'hex') AS input_hash")->sole())->toBe($before)
        ->and((string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor'))->toBe($cursor)
        ->and(DB::table('prc_admission_groups')->where('state', 'admitted')->count())->toBe(2)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe(2)
        ->and(ExecutionFixtures::laneCounts('acquisition')['outstanding'])->toBe(2)
        // The bounded feeder reselects B, preserving A's existing slots and the parent/child cap.
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(1)
        ->and(ExecutionFixtures::work($second)->state)->toBe('admitted')
        ->and(ExecutionFixtures::work($first[2])->state)->toBe('ready')
        ->and(ExecutionFixtures::laneCounts('acquisition')['outstanding'])->toBe(3)
        ->and(ExecutionFixtures::laneCounts('acquisition.listing')['outstanding'])->toBe(3)
        ->and(DB::table('prc_process_attempts')->count())->toBe(0)
        ->and(DB::table('prc_process_incidents')->count())->toBe(0);
})->with(['ready arrival' => [false], 'resume' => [true]]);
