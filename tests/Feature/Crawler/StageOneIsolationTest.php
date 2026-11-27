<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Services\Procurement\Document\DocumentFeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;

test('acquisition pause оставляет independent document backlog и не возвращает crawler downstream switch', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $f = new PrepareFixtures;
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    Queue::fake();
    expect(app(DocumentFeeder::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('state'))->toBe('ready')
        ->and(config('crawler'))->not->toHaveKey('downstream_enabled');
    Queue::assertNothingPushed();
});
