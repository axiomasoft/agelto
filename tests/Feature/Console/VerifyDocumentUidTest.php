<?php

declare(strict_types=1);

use Database\Seeders\Platforms\Eis\EisPlatformSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/** @return array{input: string, output: string} */
function documentUidCarriers(): array
{
    $directory = storage_path('framework/testing/p4-b2-'.Str::uuid());
    mkdir($directory, recursive: true);
    $scopePath = $directory.'/P4-live-scope.json';
    $outputPath = $directory.'/P4-b2-live-verification.json';
    $scope = [
        'schema_version' => 'p4-live-scope/v1',
        'plan_id' => '2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION',
        'item_id' => 'P4.1',
        'source' => ['registry' => 'eis-44fz', 'host' => 'zakupki.gov.ru', 'transport' => 'application-production-http-boundary'],
        'purposes' => ['a1-boundary-day-ordering', 'b2-document-uid-revision-pairs'],
        'a1' => ['published_window' => ['from' => '2026-09-10T00:00:00+03:00', 'to' => '2026-09-11T23:59:59+03:00'], 'max_pages' => 1],
        'b2' => ['procurement_registry_numbers' => ['0338300047926000272']],
        'request_policy' => ['total_request_ceiling' => 10, 'minimum_interval_ms' => 5000, 'max_consecutive_transport_errors' => 3, 'retry_after_required' => true],
        'evidence_policy' => ['store_response_bodies' => false, 'store_credentials' => false, 'store_signed_query_parameters' => false, 'sanitize_urls' => true],
        'stop_conditions' => ['request-ceiling-reached', 'three-consecutive-transport-errors', 'http-429-without-retry-window'],
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

test('live B2 command records current uid evidence without bodies and fails closed on absent pairs', function () {
    Sleep::fake();
    $this->seed(EisPlatformSeeder::class);
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $registryNumber = (string) ($query['regNumber'] ?? '');

        return Http::response(<<<HTML
            <html><body>{$registryNumber}<div class="blockFilesTabDocs"><div class="attachment">
            <span class="section__value"><a title="Смета.pdf" href="/44fz/filestore/file.html?uid=stable-uid&amp;token=must-not-survive">Смета.pdf</a></span>
            </div></div></body></html>
            HTML, 200, ['ETag' => 'page-etag']);
    });
    $paths = documentUidCarriers();

    $this->artisan('crawler:verify-document-uid', ['--input' => $paths['input'], '--output' => $paths['output']])
        ->assertExitCode(Command::SUCCESS);

    $artifact = json_decode((string) file_get_contents($paths['output']), true, flags: JSON_THROW_ON_ERROR);
    $encoded = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(array_keys($artifact))->toBe([
        'schema_version', 'git_anchor', 'authority_sha256', 'scope_sha256', 'pairs', 'branch',
        'revalidation_required', 'revalidation_validation', 'verdict', 'evidence_ids',
    ])->and($artifact['schema_version'])->toBe('p4-b2-live-verification/v1')
        ->and($artifact['pairs'])->toBe([])
        ->and($artifact['branch'])->toBe('insufficient_evidence')
        ->and($artifact['revalidation_required'])->toBeTrue()
        ->and($artifact['evidence_ids'])->not->toBeEmpty()
        ->and($artifact['verdict']['captures'][0]['documents'][0]['url'])->toBe('https://zakupki.gov.ru/44fz/filestore/file.html?uid=stable-uid')
        ->and($encoded)->not->toContain('<html>')
        ->and($encoded)->not->toContain('must-not-survive');
    Http::assertSentCount(1);
});
