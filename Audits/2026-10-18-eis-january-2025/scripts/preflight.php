<?php

declare(strict_types=1);

use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Proxy\ProxyResolver;
use Database\Seeders\Procurement\EisJanuary2025AuditSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$crawler = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
$evidence = [
    'utc' => gmdate(DATE_ATOM),
    'database' => config('database.connections.pgsql.database'),
    'crawler_id' => $crawler->id,
    'screening_enabled' => config('ai.screening.enabled'),
    'extraction_enabled' => config('ai.extraction.enabled'),
    'downstream_enabled' => config('crawler.downstream_enabled', true),
];

if ($evidence['screening_enabled'] || $evidence['extraction_enabled'] || $evidence['downstream_enabled']) {
    $evidence['outcome'] = 'refused_downstream_enabled';
    echo json_encode($evidence, JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}

$lease = null;
try {
    $lease = app(ProxyResolver::class)->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'ipinfo.io',
        requirements: new ProxyRequirements(
            country: 'RU',
            proxyIds: $crawler->proxies()->pluck('prc_proxies.id')->all(),
            crawlerMode: $crawler->proxy_mode,
        ),
    );
    $evidence['proxy_id'] = $lease->proxyId;
    $evidence['proxy_source'] = $lease->source?->value;
    $started = hrtime(true);
    $response = Http::withOptions($lease->httpOptions())->connectTimeout(15)->timeout(30)
        ->get('https://ipinfo.io/json');
    app(ProxyResolver::class)->reportOutcome($lease, $response->successful()
        ? ProxyOutcome::success(latencyMs: (int) ((hrtime(true) - $started) / 1_000_000))
        : ProxyOutcome::failure(ProxyFailureReason::Unknown));
    $evidence['egress_http_status'] = $response->status();
    $evidence['country'] = $response->json('country');
    $ok = $response->successful() && $evidence['country'] === 'RU' && ! $lease->isDirect;
    $evidence['outcome'] = $ok ? 'ready' : 'refused_non_ru_or_failed_egress';
    echo json_encode($evidence, JSON_PRETTY_PRINT).PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $exception) {
    if ($lease !== null && $exception instanceof ConnectionException) {
        app(ProxyResolver::class)->reportOutcome($lease, ProxyOutcome::failure(
            str_contains($exception->getMessage(), 'cURL error 28') ? ProxyFailureReason::NetworkTimeout : ProxyFailureReason::ConnectFailed,
        ));
    }
    // Transport messages can contain the authenticated proxy URL.
    $evidence['outcome'] = 'egress_failed';
    $evidence['exception_class'] = $exception::class;
    echo json_encode($evidence, JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
