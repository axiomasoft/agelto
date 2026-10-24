<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use Database\Seeders\Platforms\Eis\EisPlatformSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Platforms\Eis\EisCsvFixture;

/** @return array{input: string, output: string} */
function freshBoundaryCarriers(): array
{
    $directory = storage_path('framework/testing/p4-a1-'.Str::uuid());
    mkdir($directory, recursive: true);
    $scopePath = $directory.'/P4-live-scope.json';
    $outputPath = $directory.'/P4-a1-live-verification.json';
    $scope = [
        'schema_version' => 'p4-live-scope/v1',
        'plan_id' => '2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION',
        'item_id' => 'P4.1',
        'source' => ['registry' => 'eis-44fz', 'host' => 'zakupki.gov.ru', 'transport' => 'application-production-http-boundary'],
        'purposes' => ['a1-boundary-day-ordering', 'b2-document-uid-revision-pairs'],
        'a1' => [
            'published_window' => ['from' => '2026-07-27T00:00:00+03:00', 'to' => '2026-08-05T23:59:59+03:00'],
            'max_pages' => 2,
        ],
        'b2' => ['procurement_registry_numbers' => ['0338300047926000272']],
        'request_policy' => ['total_request_ceiling' => 10, 'minimum_interval_ms' => 5000, 'max_consecutive_transport_errors' => 3, 'retry_after_required' => true],
        'evidence_policy' => ['store_response_bodies' => false, 'store_credentials' => false, 'store_signed_query_parameters' => false, 'sanitize_urls' => true],
        'stop_conditions' => ['request-ceiling-reached'],
    ];
    file_put_contents($scopePath, json_encode($scope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $authorization = [
        'schema_version' => 'p4-live-authorization/v1',
        'plan_id' => $scope['plan_id'],
        'item_id' => 'P4.1',
        'decision' => 'authorized',
        'authorized_by' => 'repository-owner',
        'authorized_at' => now()->subMinute()->toIso8601String(),
        'expires_at' => now()->addHour()->toIso8601String(),
        'authorized_actions' => ['perform-read-only-a1-b2-live-eis-probes'],
        'scope_path' => basename($scopePath),
        'scope_sha256' => hash_file('sha256', $scopePath),
    ];
    file_put_contents($directory.'/P4-live-authorization.json', json_encode($authorization, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return ['input' => $scopePath, 'output' => $outputPath];
}

test('live A1 command uses bounded listing route and writes the exact sanitized schema', function () {
    Sleep::fake();
    $this->seed(EisPlatformSeeder::class);
    $registry = Registry::query()->where('code', 'fz44')->firstOrFail();
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);
    Crawler::factory()->for($registry)->create([
        'mode' => CrawlerMode::Fresh,
        'proxy_mode' => CrawlerProxyMode::Direct,
    ]);
    Http::fake([
        config('crawler.eis.csv_endpoint').'*' => Http::sequence()
            ->push(body: EisCsvFixture::body(), status: 200)
            ->push(body: EisCsvFixture::body(), status: 200),
    ]);
    $paths = freshBoundaryCarriers();

    $this->artisan('crawler:verify-fresh-boundary', ['--input' => $paths['input'], '--output' => $paths['output']])
        ->assertExitCode(Command::SUCCESS);

    $artifact = json_decode((string) file_get_contents($paths['output']), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($artifact))->toBe([
        'schema_version', 'git_anchor', 'authority_sha256', 'scope_sha256', 'requests', 'pages',
        'boundary_days', 'stop_reasons', 'verdict', 'evidence_ids',
    ])->and($artifact['schema_version'])->toBe('p4-a1-live-verification/v1')
        ->and($artifact['requests'])->toHaveCount(2)
        ->and($artifact['pages'])->toHaveCount(2)
        ->and($artifact['evidence_ids'])->not->toBeEmpty()
        ->and(json_encode($artifact, JSON_THROW_ON_ERROR))->not->toContain('Закупки по;');
    Http::assertSentCount(2);
});

test('непроверенный CSV в bounded probe фиксирует отказ без ложной пустой страницы', function () {
    Sleep::fake();
    $this->seed(EisPlatformSeeder::class);
    $registry = Registry::query()->where('code', 'fz44')->firstOrFail();
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);
    Crawler::factory()->for($registry)->create(['mode' => CrawlerMode::Fresh, 'proxy_mode' => CrawlerProxyMode::Direct]);
    Http::fake(['*' => static fn () => Http::response('<html>Access denied</html>', 200)]);
    $paths = freshBoundaryCarriers();

    $this->artisan('crawler:verify-fresh-boundary', ['--input' => $paths['input'], '--output' => $paths['output']])->assertExitCode(Command::SUCCESS);

    $artifact = json_decode((string) file_get_contents($paths['output']), true, flags: JSON_THROW_ON_ERROR);
    expect($artifact['requests'])->toHaveCount(3)
        ->and(array_column($artifact['requests'], 'response_error'))->toBe(['invalid_csv_header', 'invalid_csv_header', 'invalid_csv_header'])
        ->and($artifact['pages'])->toBe([])
        ->and($artifact['stop_reasons'])->toBe(['three-consecutive-response-errors'])
        ->and($artifact['verdict']['status'])->toBe('insufficient_live_boundary_evidence')
        ->and(json_encode($artifact))->not->toContain('Access denied');
    Http::assertSentCount(3);
});
