<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\ProcessCore\AcceptanceScenario;

pest()->group('delivery-and-daemon-lifecycle', 'provider-and-worker-recovery', 'faults');

test('настоящий RelayDaemon SIGKILL между commit publish и mark не теряет ту же работу', function (string $boundary): void {
    $scenario = new AcceptanceScenario;
    $relay = null;
    try {
        [$id] = $scenario->documents(1);
        $scenario->twoFeeders();
        touch($scenario->root.'/arm-'.$boundary);
        $relay = new Process([PHP_BINARY, 'tests/Support/ProcessCore/artisan.php', 'process:daemon', 'relay'], base_path(), [
            'PROCESS_CORE_ACCEPTANCE_FILE' => $scenario->root.'/acceptance.json',
            'PROCESS_CORE_QUEUE_MODE' => 'redis', 'QUEUE_CONNECTION' => 'redis',
        ]);
        $relay->setTimeout(null)->start();
        $until = hrtime(true) + 10_000_000_000;
        while (! is_file($scenario->root.'/boundary.json') && hrtime(true) < $until && $relay->isRunning()) {
            usleep(20_000);
        }
        expect(is_file($scenario->root.'/boundary.json'))->toBeTrue($relay->getOutput().$relay->getErrorOutput());
        $marker = json_decode((string) file_get_contents($scenario->root.'/boundary.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($marker['work'])->toBe($id)->and($marker['pid'])->toBe($relay->getPid());
        $relay->signal(SIGKILL);
        $relay->wait();
        expect($relay->hasBeenSignaled())->toBeTrue()->and($relay->getTermSignal())->toBe(SIGKILL)
            ->and(Queue::connection('process_ai')->size('process-ai'))->toBe($boundary === 'publish-before-mark' ? 1 : 0)
            ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNotNull('published_at')->count())->toBe(0);
        $scenario->start();
        $scenario->drain();
        expect(DB::table('prc_process_work')->where('id', $id)->value('state'))->toBe('succeeded')
            ->and(DB::table('prc_process_transitions')->where('work_id', $id)->where('new_state', 'succeeded')->count())->toBe(1)
            ->and(DB::table('prc_process_attempts')->count())->toBe(3)
            ->and($scenario->wire->receipts())->toHaveCount(3);
    } finally {
        $relay?->stop(0, SIGKILL);
        $scenario->close();
    }
})->with(['relay-claim-before-publish', 'publish-before-mark']);
