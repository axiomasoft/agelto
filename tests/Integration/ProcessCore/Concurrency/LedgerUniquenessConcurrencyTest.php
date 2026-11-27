<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Authority\ScopeDefinition;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ScopeKind;
use App\Repositories\Procurement\Process\Authority\ControlRepository;
use App\Repositories\Procurement\Process\Authority\HandlerAuthorityRepository;
use App\Repositories\Procurement\Process\Authority\LaneRepository;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('ledger-authority');

/**
 * Committed real subject (no RefreshDatabase in Integration): workers see it on their own connections.
 *
 * @return array{run: string, crawl_run: string, block: string, filter_hash: string, access_policy_hash: string}
 */
function ledgerConcurrencyFixture(): array
{
    $subject = DB::transaction(fn (): array => LedgerFixtures::crawlBlock());

    return [
        'run' => $subject['run']->value, 'crawl_run' => $subject['crawl_run']->value, 'block' => $subject['block']->value,
        'filter_hash' => $subject['filter_hash']->hex, 'access_policy_hash' => $subject['access_policy_hash']->hex,
    ];
}

/** Worker holds its uncommitted insert briefly so the competitor waits on the same unique key. */
function ledgerConcurrencyWorker(string $statement): string
{
    return <<<PHP
        try {
            \\Illuminate\\Support\\Facades\\DB::transaction(function (): void {
                {$statement}
                \\Illuminate\\Support\\Facades\\DB::select('SELECT pg_sleep(0.3)');
            });
            \$state = null;
        } catch (\\Illuminate\\Database\\QueryException \$exception) {
            \$state = (string) (\$exception->errorInfo[0] ?? \$exception->getCode());
        }

        return ['sqlstate' => \$state, 'backend_pid' => \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        PHP;
}

function ledgerConcurrencyListingStatement(array $fixture): string
{
    return sprintf(
        '\\Tests\\Support\\ProcessCore\\LedgerFixtures::storeWork(\\Tests\\Support\\ProcessCore\\LedgerFixtures::listingInput('
        ."new \\App\Dto\Procurement\Process\Primitives\ProcessUuid('%s'), new \\App\Dto\Procurement\Process\Primitives\ContentHash('%s'), new \\App\Dto\Procurement\Process\Primitives\ContentHash('%s')));",
        $fixture['block'], $fixture['filter_hash'], $fixture['access_policy_hash'],
    );
}

function ledgerConcurrencyCleanup(array $fixture): void
{
    $work = 'SELECT id FROM prc_process_work WHERE crawl_block_id = ?';
    DB::delete("DELETE FROM prc_process_outbox WHERE dedup_key IN (SELECT 'project:' || event_id FROM prc_process_transitions WHERE work_id IN ({$work}) OR run_id = ?)", [$fixture['block'], $fixture['run']]);
    DB::delete("DELETE FROM prc_process_transitions WHERE work_id IN ({$work}) OR run_id = ?", [$fixture['block'], $fixture['run']]);
    DB::delete('DELETE FROM prc_process_work WHERE crawl_block_id = ?', [$fixture['block']]);
    DB::delete('DELETE FROM prc_operation_intents WHERE subject_id = ?', [$fixture['block']]);
    DB::delete('DELETE FROM prc_crawl_blocks WHERE crawl_run_id = ?', [$fixture['crawl_run']]);
    DB::delete('DELETE FROM prc_crawl_runs WHERE id = ?', [$fixture['crawl_run']]);
    DB::delete('DELETE FROM prc_process_runs WHERE id = ?', [$fixture['run']]);
}

test('два одновременных writer одного immutable input создают ровно одну work, transition и projection intent', function (): void {
    $fixture = ledgerConcurrencyFixture();

    try {
        $statement = ledgerConcurrencyListingStatement($fixture);
        $workers = ConcurrentTransactions::concurrently([
            'first' => ledgerConcurrencyWorker($statement),
            'second' => ledgerConcurrencyWorker($statement),
        ]);
        $states = [$workers['first']['sqlstate'], $workers['second']['sqlstate']];
        sort($states);
        $work = DB::table('prc_process_work')->where('crawl_block_id', $fixture['block'])->get();
        $transitions = DB::table('prc_process_transitions')->whereIn('work_id', $work->pluck('id'))->get();

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($states)->toBe([null, '23505'])
            ->and($work)->toHaveCount(1)
            ->and($transitions)->toHaveCount(1)
            ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$transitions->sole()->event_id)->count())->toBe(1);
    } finally {
        ledgerConcurrencyCleanup($fixture);
    }
});

test('два одновременных active intent одного subject: partial unique оставляет один active', function (): void {
    $fixture = ledgerConcurrencyFixture();
    $intent = static fn (): string => sprintf(
        'app(\\App\Repositories\Procurement\Process\Work\WorkStoreRepository::class)->storeIntent('
        .'id: \\Tests\\Support\\ProcessCore\\LedgerFixtures::uuid(), intentKey: \\Tests\\Support\\ProcessCore\\LedgerFixtures::hash((string) \\Illuminate\\Support\\Str::uuid()), '
        .'stage: \\App\Enums\Procurement\Process\Core\StageCode::Listing, '
        ."subject: new \\App\Dto\Procurement\Process\Primitives\SubjectKey(\\App\Enums\Procurement\Process\Core\SubjectKind::CrawlBlock, new \\App\Dto\Procurement\Process\Primitives\ProcessUuid('%s')), "
        ."semanticPolicyHash: \\Tests\\Support\\ProcessCore\\LedgerFixtures::hash('semantic'), accessPolicyHash: \\Tests\\Support\\ProcessCore\\LedgerFixtures::hash('access'), "
        .'cycleSeq: 1, expectedHeadGeneration: 1, state: \\App\Enums\Procurement\Process\Governance\IntentState::Active);',
        $fixture['block'],
    );

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => ledgerConcurrencyWorker($intent()),
            'second' => ledgerConcurrencyWorker($intent()),
        ]);
        $states = [$workers['first']['sqlstate'], $workers['second']['sqlstate']];
        sort($states);

        expect($states)->toBe([null, '23505'])
            ->and(DB::table('prc_operation_intents')->where(['subject_id' => $fixture['block'], 'state' => 'active'])->count())->toBe(1);
    } finally {
        ledgerConcurrencyCleanup($fixture);
    }
});

test('два одновременных ensureScopes одной цепочки создают одну authority row на key и оба завершаются', function (): void {
    $platformKey = 'acquisition:platform:concurrency-'.LedgerFixtures::uuid()->value;
    $existing = DB::table('prc_control_scopes')->whereIn('scope_key', ['system', 'acquisition'])->pluck('scope_key')->all();
    $chain = sprintf(
        'app(\\App\Repositories\Procurement\Process\Authority\ControlRepository::class)->ensureScopes(['
        ."new \\App\Dto\Procurement\Process\Authority\ScopeDefinition(\\App\Enums\Procurement\Process\Governance\ScopeKind::Platform, '%s', 'acquisition'), "
        ."new \\App\Dto\Procurement\Process\Authority\ScopeDefinition(\\App\Enums\Procurement\Process\Governance\ScopeKind::Acquisition, 'acquisition', 'system'), "
        ."new \\App\Dto\Procurement\Process\Authority\ScopeDefinition(\\App\Enums\Procurement\Process\Governance\ScopeKind::System, 'system', null)]);",
        $platformKey,
    );

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => ledgerConcurrencyWorker($chain),
            'second' => ledgerConcurrencyWorker($chain),
        ]);
        $rows = DB::table('prc_control_scopes')->whereIn('scope_key', ['system', 'acquisition', $platformKey])->get()->keyBy('scope_key');

        expect([$workers['first']['sqlstate'], $workers['second']['sqlstate']])->toBe([null, null])
            ->and($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($rows)->toHaveCount(3)
            ->and($rows[$platformKey]->parent_id)->toBe($rows['acquisition']->id)
            ->and($rows[$platformKey]->desired_mode)->toBe('enabled');
    } finally {
        DB::delete('DELETE FROM prc_control_scopes WHERE scope_key = ?', [$platformKey]);
        foreach (array_diff(['acquisition', 'system'], $existing) as $key) {
            DB::delete('DELETE FROM prc_control_scopes WHERE scope_key = ?', [$key]);
        }
    }
});

test('authority writers вне транзакции вызывающего отвергаются и ничего не пишут', function (): void {
    $before = DB::table('prc_control_scopes')->count() + DB::table('prc_process_lanes')->count() + DB::table('prc_process_handler_holds')->count();

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(fn () => app(ControlRepository::class)->ensureScopes([new ScopeDefinition(ScopeKind::System, 'system', null)]))
        ->toThrow(LogicException::class, 'caller ledger transaction')
        ->and(fn () => app(LaneRepository::class)->syncLanes(app(ProcessPolicy::class)->lanes(), 1))
        ->toThrow(LogicException::class, 'caller ledger transaction')
        ->and(fn () => app(HandlerAuthorityRepository::class)->register(StageCode::Listing, 'fixture-listing/1'))
        ->toThrow(LogicException::class, 'caller ledger transaction')
        ->and(DB::table('prc_control_scopes')->count() + DB::table('prc_process_lanes')->count() + DB::table('prc_process_handler_holds')->count())->toBe($before);
});

test('ledger writer вне транзакции вызывающего отвергается и ничего не пишет', function (): void {
    $fixture = ledgerConcurrencyFixture();

    try {
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(fn () => LedgerFixtures::storeWork(
                LedgerFixtures::listingInput(
                    new ProcessUuid($fixture['block']),
                    new ContentHash($fixture['filter_hash']),
                    new ContentHash($fixture['access_policy_hash']),
                ),
                id: new WorkId(LedgerFixtures::uuid()),
            ))->toThrow(LogicException::class, 'caller ledger transaction')
            ->and(DB::table('prc_process_work')->where('crawl_block_id', $fixture['block'])->count())->toBe(0);
    } finally {
        ledgerConcurrencyCleanup($fixture);
    }
});
