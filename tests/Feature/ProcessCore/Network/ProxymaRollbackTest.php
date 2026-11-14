<?php

declare(strict_types=1);

use App\Services\Procurement\Proxyma\Migration\Cutover;
use App\Services\Procurement\Proxyma\Migration\CutoverJournal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\ProcessCore\CutoverFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProviderSendFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;
use Tests\Support\ProcessCore\ProxymaProviderFixtures;
use Tests\Support\ProcessCore\ProxymaUpgradeFixtures as U;

afterEach(function (): void {
    ProxymaFixtures::resetAuthority();
    DB::statement('TRUNCATE prc_network_budgets CASCADE');
});

test('до первого нового claim rollback доказывает точное равенство counters epochs receipts и удержаний', function (): void {
    [$command, $id, $f] = F::prepare();
    expect($command->run($id, $f['map'], 1, 0))->toBe('accepted');
    $before = F::snapshot();
    expect($command->rollback($id, 2, 0))->toBe('rolled_back')
        ->and(F::snapshot())->toBe($before)
        ->and(DB::table('prc_network_authorities')->sole()->authority)->toBe('legacy')
        ->and(DB::table('prc_network_authorities')->sole()->generation)->toBe(3)
        ->and(fn () => $command->run($id, $f['map'], 1, 0))->toThrow(DomainException::class);
});

test('изменённый source или target не выдаётся за точный rollback и остаётся held', function (string $side): void {
    [$command, $id, $f] = F::prepare();
    expect($command->run($id, $f['map'], 1, 0))->toBe('accepted');
    if ($side === 'target') {
        DB::table(PackageSchema::tables()['budgets'])->increment('revision');
    } else {
        DB::table('prc_request_attempts')->where('request_id', $f['request'])->update(['bytes' => 1]);
    }
    expect(fn () => $command->rollback($id, 2, 0))->toThrow(DomainException::class)
        ->and(DB::table('prc_network_authorities')->sole()->authority)->toBe('proxyma')
        ->and(DB::table('prc_network_authorities')->sole()->paused)->toBeTrue();
})->with(['source', 'target']);

test('первый joint claim без socket делает rollback необратимым сохраняет finance hold и не даёт stale worker send', function (): void {
    $wire = new WireFixture;
    try {
        $f = new ProviderSendFixtures($wire);
        new ProxymaProviderFixtures($f);
        $gate = $f->gate();
        $reservationMarkers = [];
        DB::listen(function ($query) use (&$reservationMarkers): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "pxm_permits"')) {
                $reservationMarkers[] = DB::table('prc_network_cutover_journals')->sole()->first_authorized_at;
            }
        });
        $claim = $gate->claimSend($f->operation, $f->wire);
        expect($reservationMarkers)->toBe([null]);
        expect($claim->transport)->not->toBeNull()->and($wire->receipts())->toBe([]);
        $selector = DB::table('prc_network_authorities')->sole();
        $journal = DB::table('prc_network_cutover_journals')->where('id', $selector->cutover_id)->sole();
        expect($journal->first_authorized_at)->not->toBeNull();
        [$inventory] = U::services();
        $cutover = new Cutover(new CutoverJournal($inventory));
        $before = F::snapshot();
        // Simulate loss of capability after commit, before a byte can reach the fixture.
        unset($claim, $gate);
        expect($cutover->rollback($selector->cutover_id, 2, 0))->toBe('rollback_refused_held')
            ->and(F::snapshot())->toBe($before)
            ->and(DB::table('prc_network_authorities')->sole()->paused)->toBeTrue()
            ->and(DB::table('prc_network_cutover_journals')->where('id', $selector->cutover_id)->sole()->first_authorized_at)->toBe($journal->first_authorized_at)
            ->and(fn () => $cutover->resume($selector->cutover_id, 2))->toThrow(DomainException::class)
            ->and($wire->receipts())->toBe([]);
        expect(fn () => DB::transaction(fn () => DB::table('prc_network_cutover_journals')->where('id', $journal->id)->update(['first_authorized_at' => null])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('prc_network_authorities')->update(['authority' => 'legacy', 'cutover_id' => null])))
            ->toThrow(QueryException::class);
        expect(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(1)
            ->and(DB::table('llm_budget_reservations')->where('state', 'settled')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
});
