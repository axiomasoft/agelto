<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\PackageNetworkFacts;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;

pest()->group('transport-and-work-budget', 'document-processing', 'proxy-failover');

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $this->wire = new WireFixture;
});

afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});

test('download переключает недоступный proxy на разрешённый, а одиночный proxy не получает direct fallback', function (bool $alternate): void {
    $this->wire->respond('/document', "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF\n");
    $f = new DownloadFixtures($this->wire, tls: true);
    ProxymaFixtures::resetAuthority();
    $first = HistoricalNetworkFixtures::proxy(['host' => '127.0.0.1', 'port' => 1]);
    $routes = [$first];
    if ($alternate) {
        $second = HistoricalNetworkFixtures::proxy(['host' => '127.0.0.1', 'port' => $this->wire->ports['proxy']]);
        if (strcmp($first->route_id, $second->route_id) > 0) {
            DB::table('prc_proxies')->where('route_id', $first->route_id)->update(['port' => $this->wire->ports['proxy']]);
            DB::table('prc_proxies')->where('route_id', $second->route_id)->update(['port' => 1]);
            $bad = $second;
        } else {
            $bad = $first;
        }
        $routes[] = $second;
    } else {
        $bad = $first;
    }
    $profile = DB::table('prc_network_route_profiles')->where('profile_key', config()->string('outbound.routing.default_profile'))->sole();
    DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->delete();
    foreach ($routes as $route) {
        DB::table('prc_network_route_profile_members')->insert(['profile_id' => $profile->id, 'route_id' => $route->route_id]);
    }
    HistoricalNetworkFixtures::synchronize();
    config()->set('proxyma.private_proxies', ['127.0.0.1' => ['127.0.0.1']]);
    ProxymaCallerFixtures::install($this->wire, routeStrategy: 'least_recent_authorized');
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf($alternate ? StageSucceeded::class : StageDeferred::class);
    expect(app(WorkFinalizer::class)->finish($context->lease(), $outcome)->state)->toBe($alternate ? WorkState::Succeeded : WorkState::Deferred);
    $attempts = PackageNetworkFacts::attempts()->orderBy('started_at')->get();
    expect($attempts)->toHaveCount($alternate ? 2 : 3)
        ->and($attempts->first()->route_id)->toBe($bad->route_id)
        ->and($attempts->pluck('route_id')->unique()->count())->toBe($alternate ? 2 : 1)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_document_versions')->count())->toBe($alternate ? 1 : 0)
        ->and(array_values(array_filter($this->wire->receipts(), static fn (array $receipt): bool => $receipt['kind'] === 'http')))
        ->toHaveCount($alternate ? 1 : 0);
})->with(['разрешённый proxy' => true, 'одиночный недоступный proxy' => false]);
