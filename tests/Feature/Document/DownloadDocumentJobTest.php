<?php

declare(strict_types=1);

use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

test('повторная download delivery не запускает handler владельца второй раз', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        [, $work] = $f->running();
        $job = new ExecuteWorkJob($work->value->value, ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value);
        $job->handle(app(WorkRunner::class));
        $job->handle(app(WorkRunner::class));
        expect(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count())->toBe(1)
            ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('running')
            ->and($wire->receipts())->toBeEmpty();
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});
