<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelRun;
use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ControlMode;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(fn () => F::bind());

/** Complete exactly one assigned day, including its mandatory full stability pass. */
function finishArchiveDay(ProcessUuid $crawl, string $day): int
{
    $count = 0;
    while (($row = DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
        ->where('b.crawl_run_id', $crawl->value)->where('b.partition_payload->windowStart', $day)
        ->whereIn('w.state', ['ready', 'admitted'])->first(['w.id', 'w.input_payload'])) !== null) {
        expect(++$count)->toBeLessThanOrEqual(2);
        $input = app(ProcessPayloadCodec::class)->decodeInput(new VersionedPayload($row->input_payload, 1));
        expect($input)->toBeInstanceOf(ListingInput::class);
        F::finish(new WorkId(new ProcessUuid($row->id)), $input, F::page([F::summary('archive-'.$day)]));
    }

    return $count;
}

test('Process archive covers both assigned days and closes discovery with required detail obligations', function (): void {
    $f = F::run('2024-01-01', '2024-01-02');
    expect(finishArchiveDay($f['crawl'], '2024-01-01'))->toBe(2)
        ->and(finishArchiveDay($f['crawl'], '2024-01-02'))->toBe(2)
        ->and(Procurement::query()->count())->toBe(2)
        ->and(app(ListingCoverageRepository::class)->read($f['stream'])->verifiedThrough)->toBe('2024-01-02')
        ->and(DB::table('prc_process_runs')->where('id', $f['run']->value)->value('discovery_outcome'))->toBe('complete')
        ->and(DB::table('prc_process_run_work as rw')->join('prc_process_work as w', 'w.id', '=', 'rw.work_id')
            ->where('rw.run_id', $f['run']->value)->where('rw.accounting_role', 'required')->where('w.stage', 'detail')->count())->toBe(2);
});

test('Process archive resumes remaining day without recreating completed day work', function (): void {
    $f = F::run('2024-01-01', '2024-01-02');
    finishArchiveDay($f['crawl'], '2024-01-01');
    $completed = DB::table('prc_process_work')->where('state', 'succeeded')->orderBy('id')->pluck('id')->all();
    E::setMode('acquisition', ControlMode::Paused);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    E::setMode('acquisition', ControlMode::Enabled);
    expect(finishArchiveDay($f['crawl'], '2024-01-02'))->toBe(2)
        ->and(finishArchiveDay($f['crawl'], '2024-01-01'))->toBe(0)
        ->and(DB::table('prc_process_attempts')->whereIn('work_id', $completed)->count())->toBe(2)
        ->and(Procurement::query()->count())->toBe(2);
});

test('Process archive pause between days retains ready assignments and prohibits the next grant', function (): void {
    $f = F::run('2024-01-01', '2024-01-03');
    finishArchiveDay($f['crawl'], '2024-01-01');
    E::setMode('acquisition', ControlMode::Paused);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(Procurement::query()->count())->toBe(1)
        ->and(app(ListingCoverageRepository::class)->read($f['stream'])->verifiedThrough)->toBe('2024-01-01')
        ->and(DB::table('prc_process_runs')->where('id', $f['run']->value)->value('discovery_outcome'))->toBe('collecting')
        ->and(DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
            ->where('b.partition_payload->windowStart', '2024-01-02')->value('w.execution_attempts'))->toBe(0);
});

test('Process archive hard cancellation stops remaining exclusive days without claiming coverage', function (): void {
    $f = F::run('2024-01-01', '2024-01-03');
    finishArchiveDay($f['crawl'], '2024-01-01');
    $seq = DB::table('prc_process_runs')->where('id', $f['run']->value)->value('transition_seq');
    app(CancelRun::class)->execute($f['run'], 'operator cancel archive', $seq);
    // CancelRun owns the intent; explicit CancelWork fences the exclusively assigned pages.
    foreach (DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
        ->where('b.crawl_run_id', $f['crawl']->value)->whereIn('w.state', ['ready', 'admitted'])->pluck('w.id') as $id) {
        app(CancelWork::class)->execute(new WorkId(new ProcessUuid($id)), 'operator hard cancel remaining archive page');
    }
    expect(DB::table('prc_process_runs')->where('id', $f['run']->value)->value('cancel_requested_at'))->not->toBeNull()
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)->and(F::next($f['crawl']))->toBeNull()
        ->and(Procurement::query()->count())->toBe(1)
        ->and(app(ListingCoverageRepository::class)->read($f['stream'])->verifiedThrough)->toBe('2024-01-01')
        ->and(DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
            ->where('b.partition_payload->windowStart', '2024-01-02')->value('w.state'))->toBe('cancelled')
        ->and(DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
            ->where('b.partition_payload->windowStart', '2024-01-02')->value('w.execution_attempts'))->toBe(0);
});
