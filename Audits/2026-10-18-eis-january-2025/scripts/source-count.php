<?php

declare(strict_types=1);

use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Eis\EisCsvUrlBuilder;
use App\Services\Procurement\PlatformRequestBudget;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\SourceTlsTrust;
use Database\Seeders\Procurement\EisJanuary2025AuditSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$result = ['utc' => gmdate(DATE_ATOM), 'outcome' => 'not_verified', 'attempts' => []];
try {
    $crawler = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);
    $params = array_filter($params, static fn ($key): bool => ! str_ends_with($key, 'Csv') && ! in_array($key, ['from', 'to'], strict: true), ARRAY_FILTER_USE_KEY);
    $params['priceFromGeneral'] = (string) ($crawler->filter->priceFrom ?? 0);
    $params['priceToGeneral'] = (string) ($crawler->filter->priceTo ?? config('crawler.archive.price_ceiling'));
    if (in_array('--without-price', $argv, strict: true)) {
        unset($params['priceFromGeneral'], $params['priceToGeneral']);
    }
    $params['recordsPerPage'] = '_10';
    $params['pageNumber'] = '1';
    $result['request_params'] = $params;
    $lease = app(ProxyResolver::class)->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(country: 'RU', proxyIds: $crawler->proxies()->pluck('prc_proxies.id')->all(), crawlerMode: $crawler->proxy_mode),
    );
    $result['proxy_id'] = $lease->proxyId;
    $result['proxy_source'] = $lease->source?->value;
    if ($lease->isDirect) {
        throw new LogicException('Direct traffic refused.');
    }
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        app(PlatformRequestBudget::class)->acquire(host: 'zakupki.gov.ru', stage: CrawlStage::Listing, crawler: $crawler);
        $started = microtime(true);
        try {
            $response = Http::withOptions([...$lease->httpOptions(), ...app(SourceTlsTrust::class)->optionsFor('zakupki.gov.ru')])
                ->withUserAgent(config('crawler.user_agent'))->connectTimeout(10)->timeout(30)
                ->get('https://zakupki.gov.ru/epz/order/extendedsearch/results.html', $params);
            $body = $response->body();
            $observation = ['attempt' => $attempt, 'status' => $response->status(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'bytes' => strlen($body), 'sha256' => hash('sha256', $body)];
            $matched = preg_match('/downloadCsv\(\s*\x27[^\x27]*\x27\s*,\s*\x27?(\d+)\x27?/', $body, $matches) === 1;
            $observation['total_count_found'] = $matched;
            $result['attempts'][] = $observation;
            if ($response->successful() && $matched) {
                $result['source_total_count'] = (int) $matches[1];
                $result['outcome'] = 'source_count_observed';
                file_put_contents(storage_path('app/eis-january-2025-ssr.html'), $body);
                break;
            }
        } catch (Throwable $exception) {
            $result['attempts'][] = ['attempt' => $attempt, 'exception_class' => $exception::class, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];
        }
    }
} catch (Throwable $exception) {
    $result['exception_class'] = $exception::class;
}
$result['finished_at_utc'] = gmdate(DATE_ATOM);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['outcome'] === 'source_count_observed' ? 0 : 2);
