<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Services\Procurement\Proxyma\Caller\CallerGate;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound;
use App\Services\Procurement\Proxyma\Catalog\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\Catalog\ProfileBindings;
use App\Services\Procurement\Proxyma\Migration\Cutover;
use App\Services\Procurement\Proxyma\Migration\CutoverJournal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\CutoverFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProviderSendFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;
use Tests\Support\ProcessCore\ProxymaProviderFixtures;

afterEach(function (): void {
    ProxymaFixtures::resetAuthority();
    DB::statement('TRUNCATE prc_network_budgets CASCADE');
});

test('caller transaction не может скрыть phase commits или удерживать SQL locks во время drain ожидания', function (): void {
    [$command, $id] = F::prepare(populated: false);
    expect(fn () => DB::transaction(fn () => $command->run($id, [], 1, 0)))->toThrow(DomainException::class)
        ->and(DB::table('prc_network_cutover_journals')->count())->toBe(0)
        ->and(DB::table('prc_network_authorities')->sole()->paused)->toBeFalse();
});

test('cutover возобновляется после каждого durable шага без повторного spend и dual authority', function (string $phase): void {
    [$command, $id, $f, $inventory] = F::prepare(function (string $at) use ($phase): void {
        if ($at === $phase) {
            throw new RuntimeException('fixture crash after commit');
        }
    });
    expect(fn () => $command->run($id, $f['map'], 1, 0))->toThrow(RuntimeException::class);
    $selector = DB::table('prc_network_authorities')->sole();
    expect($selector->authority)->toBe(in_array($phase, ['switched', 'accepted'], true) ? 'proxyma' : 'legacy');
    $snapshot = in_array($phase, ['transferred', 'verified', 'switched', 'accepted'], true) ? F::snapshot() : null;
    $rerun = new Cutover(new CutoverJournal($inventory));
    expect($rerun->run($id, $f['map'], $selector->generation, 0))->toBe('accepted')
        ->and(DB::table('prc_network_authorities')->sole()->generation)->toBe(2)
        ->and(DB::table('prc_network_cutover_journals')->sole()->first_authorized_at)->toBeNull();
    if ($snapshot !== null) {
        expect(F::snapshot())->toBe($snapshot);
    }
    expect(fn () => DB::transaction(fn () => DB::table('prc_network_budgets')->increment('policy_version')))->toThrow(QueryException::class);
})->with(['prepared', 'draining', 'transferred', 'verified', 'switched', 'accepted']);

test('bounded drain не освобождает expired sent permit и financial unknown и блокирует новую выдачу', function (): void {
    [$command, $id, $f] = F::prepare();
    DB::table('prc_network_permits')->where('id', $f['permit'])->update(['state' => 'sent']);
    $before = F::snapshot();
    $start = hrtime(true);
    expect($command->run($id, $f['map'], 1, 30))->toBe('drain_timeout_held')
        ->and(hrtime(true) - $start)->toBeLessThan(5_000_000_000)
        ->and(DB::table('prc_network_permits')->where('id', $f['permit'])->sole()->state)->toBe('sent')
        ->and(DB::table('prc_network_authorities')->sole()->paused)->toBeTrue();
    $after = F::snapshot();
    foreach (['prc_network_budgets', 'llm_budget_reservations', 'llm_provider_calls', 'prc_request_attempts'] as $table) {
        expect($after[$table])->toBe($before[$table]);
    }
    $wire = new WireFixture;
    try {
        $fixture = $f['f'];
        $context = $fixture->context();
        $gate = new CallerGate(new ProfileBindings([]));
        expect(app(CallerOutbound::class)->execute($fixture->plan(), $context, $gate, $wire->policy(), new TransferBudget(65536, 65536)))
            ->toBeInstanceOf(TransportBlocked::class)->and($wire->receipts())->toBe([]);
    } finally {
        $wire->stop();
    }
});

test('две SQL сессии не выдают legacy claim после persisted pause', function (): void {
    [$command, $id, $f] = F::prepare();
    DB::table('prc_network_permits')->where('id', $f['permit'])->update(['state' => 'reserved', 'authorized_at' => null,
        'expires_at' => DB::raw("clock_timestamp() + interval '1 minute'")]);
    expect($command->run($id, $f['map'], 1, 0))->toBe('drain_timeout_held');
    $results = ConcurrentTransactions::concurrently([
        'old' => 'try { \Illuminate\Support\Facades\DB::table("prc_network_permits")->whereNull("authorized_at")->update(["authorized_at"=>\Illuminate\Support\Facades\DB::raw("clock_timestamp()")]); return ["denied"=>false]; } catch (\Illuminate\Database\QueryException) { return ["denied"=>true]; }',
        'observer' => '$s=\Illuminate\Support\Facades\DB::table("prc_network_authorities")->sole(); return ["authority"=>$s->authority,"paused"=>$s->paused];',
    ]);
    expect($results['old']['denied'])->toBeTrue()->and($results['observer'])->toBe(['authority' => 'legacy', 'paused' => true])
        ->and(DB::table('prc_network_permits')->where('id', $f['permit'])->sole()->authorized_at)->toBeNull()
        ->and(DB::table('pxm_attempts')->count())->toBe(0);
});

test('fresh source drift и target eligibility drift оставляют selector paused до решения', function (string $side): void {
    [$command, $id, $f] = F::prepare(function (string $phase) use ($side): void {
        if ($phase !== 'transferred') {
            return;
        }
        if ($side === 'source') {
            DB::table('prc_network_budgets')->increment('policy_version');
        } else {
            DB::table(PackageSchema::tables()['route_profile_members'])->delete();
        }
    });
    expect(fn () => $command->run($id, $f['map'], 1, 0))->toThrow(DomainException::class)
        ->and(DB::table('prc_network_authorities')->sole()->authority)->toBe('legacy')
        ->and(DB::table('prc_network_authorities')->sole()->paused)->toBeTrue();
})->with(['source', 'target']);

test('snapshot старого package worker отвергается после смены selector generation в другой SQL сессии', function (): void {
    $wire = new WireFixture;
    try {
        $f = new ProviderSendFixtures($wire);
        new ProxymaProviderFixtures($f);
        $owners = app(ProcessOwnerAuthority::class);
        $owner = $owners->reference($f->lease, new ProviderKey('fixture-provider'));
        $snapshot = $owners->snapshot($owner);
        expect($snapshot)->not->toBeNull();
        ConcurrentTransactions::concurrently(['switch' => '\Illuminate\Support\Facades\DB::table("prc_network_authorities")->increment("generation"); return ["changed"=>true];']);
        $result = ProxymaFixtures::locked(fn ($tx) => $owners->lockAndValidate($owner, $snapshot, $tx));
        expect($result)->toBeInstanceOf(ExecutionBlocked::class)
            ->and(DB::table('prc_network_cutover_journals')->sole()->first_authorized_at)->toBeNull()
            ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0)
            ->and($wire->receipts())->toBe([]);
    } finally {
        $wire->stop();
    }
});
