<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Acquisition\Detail\IdentityResolver;
use App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer;
use App\Support\Database\PostgresAdvisoryLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\IdentityFixtureDefinition;
use Tests\Support\Procurement\SnapshotFactory;

beforeEach(function (): void {
    config(['platforms.definitions.identity_fixture' => IdentityFixtureDefinition::class]);
});

test('batch locks sort adjacent signed64 keys without floating-point rounding', function (): void {
    $registry = Registry::factory()->eis()->create();
    $a = app(IdentityResolver::class)->forRegistry($registry->id, 'A');
    $b = app(IdentityResolver::class)->forRegistry($registry->id, 'B');
    DB::transaction(function () use ($a, $b): void {
        DB::unprepared("CREATE FUNCTION public.hashtextextended(text, bigint) RETURNS bigint LANGUAGE sql IMMUTABLE AS $$ SELECT CASE WHEN \$1::jsonb->>3 = 'A' THEN 9223372036854775807::bigint ELSE 9223372036854775806::bigint END $$");
        DB::statement('SET LOCAL search_path = public, pg_catalog');
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(PostgresAdvisoryLock::class)->acquireProcurements([$a, $b, $a]);
            $queries = array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'pg_advisory_xact_lock')));
            expect(array_column($queries, 'bindings'))->toBe([[PHP_INT_MAX - 1], [PHP_INT_MAX]]);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    });
})->group('platform-identity-ownership');

test('exact ON CONFLICT rereads the existing row without restarting publication', function (): void {
    $registry = Registry::factory()->eis()->create();
    $snapshot = SnapshotFactory::make($registry, ['registryNumber' => '00042']);
    $identity = app(IdentityResolver::class)->resolve($registry->id, $snapshot->identity);
    DB::transaction(function () use ($snapshot, $identity): void {
        $store = app(ProcurementStoreRepository::class);
        $first = $store->storeProcurement($snapshot, 'unknown', now(), $identity);
        $second = $store->storeProcurement($snapshot, 'unknown', now(), $identity);
        expect($first->wasRecentlyCreated)->toBeTrue()->and($second->wasRecentlyCreated)->toBeFalse()
            ->and($second->id)->toBe($first->id)->and(Procurement::query()->count())->toBe(1);
    });
})->group('platform-identity-ownership');

test('opaque identity сохраняет нули, регистр, whitespace и Unicode representation', function (): void {
    $registry = Registry::factory()->eis()->create();
    $values = ['00042', '42', 'Ab-1', 'ab-1', ' x', 'x ', 'x', 'é', "e\u{0301}", 'a:b|c'];
    foreach ($values as $value) {
        $snapshot = SnapshotFactory::make($registry, ['registryNumber' => $value]);
        $first = app(SnapshotFinalizer::class)->ingest($snapshot);
        $repeat = app(SnapshotFinalizer::class)->ingest($snapshot);
        expect($repeat->id)->toBe($first->id)->and($repeat->source_identity_value)->toBe($value)
            ->and($repeat->revision_count)->toBe(1);
    }
    expect(Procurement::query()->count())->toBe(count($values));
    $collations = DB::select("SELECT a.attname, c.collname FROM pg_attribute a JOIN pg_collation c ON c.oid=a.attcollation
        WHERE a.attrelid='prc_procurements'::regclass AND a.attname IN ('source_identity_type','source_identity_value')");
    expect(array_column($collations, 'collname'))->toBe(['C', 'C']);
})->group('platform-identity-ownership');

test('equal values платформ и независимо нумеруемых реестров не объединяются', function (): void {
    $eis = Registry::factory()->eis()->create();
    $platform = Platform::factory()->create(['code' => 'identity_fixture']);
    $main = Registry::factory()->for($platform)->create(['code' => 'main']);
    $secondary = Registry::factory()->for($platform)->create(['code' => 'secondary']);
    $ids = [];
    foreach ([$eis, $main, $secondary] as $registry) {
        $ids[] = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, ['registryNumber' => '00042']))->id;
    }
    expect(array_unique($ids))->toHaveCount(3);
    $identity = app(IdentityResolver::class)->forRegistry($main->id, '00042');
    expect(fn () => app(IdentityResolver::class)->resolve($eis->id, $identity->source))->toThrow(InvalidArgumentException::class);
    $procurement = Procurement::query()->findOrFail($ids[0]);
    expect(fn () => $procurement->update(['source_identity_value' => 'different']))->toThrow(LogicException::class);
    expect(Procurement::query()->findOrFail($ids[0])->source_identity_value)->toBe('00042');
    expect(fn () => DB::transaction(fn () => DB::table('prc_procurements')->where('id', $ids[0])->update(['platform_id' => $platform->id])))
        ->toThrow(QueryException::class);
})->group('platform-identity-ownership');

test('invalid opaque values отклоняются до persistence', function (string $value): void {
    expect(fn () => new SourceIdentifierValue($value))->toThrow(InvalidPlatformDefinition::class);
})->with(['empty' => '', 'nul' => "a\0b", 'invalid utf8' => "\xff", 'overlong' => str_repeat('я', 201)])->group('platform-identity-ownership');

test('lock encoding не смешивает delimiters и не меняет exact tuple lookup', function (): void {
    $registry = Registry::factory()->eis()->create();
    $a = app(IdentityResolver::class)->forRegistry($registry->id, 'a:b');
    $b = app(IdentityResolver::class)->forRegistry($registry->id, 'a|b');
    expect(json_decode($a->lockMaterial(), true))->toBe(['procurement', $registry->platform_id, 'registry:44fz', 'a:b'])
        ->and($a->lockMaterial())->not->toBe($b->lockMaterial());
    // Force the helper's actual hash result to collide; tuple SELECT must remain exact.
    DB::transaction(function () use ($registry): void {
        DB::unprepared('CREATE FUNCTION public.hashtextextended(text, bigint) RETURNS bigint LANGUAGE sql IMMUTABLE AS $$ SELECT 441142::bigint $$');
        DB::statement('SET LOCAL search_path = public, pg_catalog');
        expect((int) DB::scalar("SELECT hashtextextended('any tuple', 0)"))->toBe(441142);
        foreach (['a:b', 'a|b'] as $value) {
            app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, ['registryNumber' => $value]));
        }
    });
    expect(Procurement::query()->count())->toBe(2);
})->group('platform-identity-ownership');

test('два concurrent blocks commit одну identity без повторного HTTP', function (): void {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
\Tests\Support\ProcessCore\ListingFixtures::bind();
$registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
return ['registry' => $registry->id, 'platform' => $registry->platform_id];
PHP);
    $wire = new WireFixture;
    $barrier = sys_get_temp_dir().'/identity-'.bin2hex(random_bytes(8));
    mkdir($barrier);
    $wire->respond('/identity', '00042');
    try {
        $prefix = '\\Tests\\Support\\ProcessCore\\IdentityConcurrency::worker('.$fixture['registry'].','.$wire->ports['http'].','.var_export($barrier, true).',';
        $workers = ConcurrentTransactions::concurrently([
            'first' => 'return '.$prefix.'true);',
            'second' => 'return '.$prefix.'false);',
        ]);
        expect($workers['first']['pid'])->not->toBe($workers['second']['pid'])
            ->and($workers['first']['procurement_id'])->toBe($workers['second']['procurement_id'])
            ->and($workers['first']['committed'])->toBeTrue()->and($workers['second']['committed'])->toBeTrue()
            ->and($wire->receipts())->toHaveCount(2);
        // Read committed state on a separate connection too: retaining parent SELECT locks
        // would block the child's ledger cleanup at the end of this transactional test.
        $committed = ConcurrentTransactions::execute('
            $p = \\App\\Models\\Procurement\\Procurement::query()->where("registry_id", '.$fixture['registry'].')->sole();
            return ["revisions" => $p->revision_count,
                "history" => \\Illuminate\\Support\\Facades\\DB::table("prc_revisions")->where("procurement_id", $p->id)->count(),
                "completed" => \\Illuminate\\Support\\Facades\\DB::table("prc_process_work")->whereIn("id", '.var_export(array_column($workers, 'work_id'), true).')->where("state", "succeeded")->count(),
                "held" => \\Illuminate\\Support\\Facades\\DB::table("prc_process_handler_holds")->where("state", "held")->count()];');
        expect($committed)->toBe(['revisions' => 1, 'history' => 1, 'completed' => 2, 'held' => 0]);
    } finally {
        $wire->stop();
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($barrier);
        ConcurrentTransactions::execute('
            \\Tests\\Support\\ProcessCore\\ExecutionFixtures::truncateLedger();
            $ids = \\Illuminate\\Support\\Facades\\DB::table("prc_procurements")->where("registry_id", '.$fixture['registry'].')->pluck("id");
            foreach (["prc_events", "prc_revisions"] as $table) { \\Illuminate\\Support\\Facades\\DB::table($table)->whereIn("procurement_id", $ids)->delete(); }
            \\Illuminate\\Support\\Facades\\DB::table("prc_procurements")->whereIn("id", $ids)->delete();
            \\Illuminate\\Support\\Facades\\DB::table("prc_status_mappings")->where("registry_id", '.$fixture['registry'].')->delete();
            \\Illuminate\\Support\\Facades\\DB::table("prc_registries")->where("id", '.$fixture['registry'].')->delete();
            \\Illuminate\\Support\\Facades\\DB::table("prc_platforms")->where("id", '.$fixture['platform'].')->delete();
            return ["cleaned" => true];');
    }
})->group('platform-identity-ownership');
