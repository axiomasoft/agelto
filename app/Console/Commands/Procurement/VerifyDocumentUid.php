<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Dto\Procurement\Crawler\StageSettings;
use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\Card\DocumentsParser;
use App\Services\Platforms\Eis\Card\EisCardUrlBuilder;
use App\Services\Procurement\CrawlSettingsResolver;
use App\Services\Procurement\PlatformRequestBudget;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\SourceTlsTrust;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

#[Description('Performs the owner-authorized bounded B2 EIS attachment uid verification')]
#[Signature('crawler:verify-document-uid {--input= : Authorized P4 scope JSON} {--output= : Sanitized verification JSON}')]
final class VerifyDocumentUid extends Command
{
    public function __construct(
        private readonly EisCardUrlBuilder $urls,
        private readonly DocumentsParser $documents,
        private readonly ProxyResolver $proxies,
        private readonly PlatformRequestBudget $budget,
        private readonly CrawlSettingsResolver $crawlSettings,
        private readonly SourceTlsTrust $tlsTrust,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            [$scope, $authoritySha, $scopeSha] = $this->loadCarriers();
            $registry = $this->registry();
            $templates = $this->templateTypes($registry);
            $numbers = $this->stringList(
                $this->array($scope['b2'] ?? null, 'b2')['procurement_registry_numbers'] ?? null,
                'b2.procurement_registry_numbers',
            );
            $policy = $this->array($scope['request_policy'] ?? null, 'request_policy');
            $ceiling = $this->positiveInt($policy['total_request_ceiling'] ?? null, 'request_policy.total_request_ceiling');
            $intervalMs = $this->positiveInt($policy['minimum_interval_ms'] ?? null, 'request_policy.minimum_interval_ms');
            $maxErrors = $this->positiveInt($policy['max_consecutive_transport_errors'] ?? null, 'request_policy.max_consecutive_transport_errors');
            $a1MaxPages = $this->positiveInt($this->array($scope['a1'] ?? null, 'a1')['max_pages'] ?? null, 'a1.max_pages');

            if ($a1MaxPages + (count($numbers) * count($templates)) > $ceiling) {
                throw new \RuntimeException('Authorized A1+B2 worst-case requests exceed the total request ceiling.');
            }

            $captures = [];
            $evidenceIds = [];
            $requestCount = 0;
            $consecutiveErrors = 0;
            $stopReasons = [];
            $stop = false;

            foreach ($numbers as $registryNumber) {
                foreach ($templates as $procedureType) {
                    if ($requestCount >= $ceiling - $a1MaxPages) {
                        $stopReasons[] = 'request-ceiling-reached';
                        $stop = true;

                        break;
                    }

                    if ($requestCount > 0) {
                        Sleep::for($intervalMs)->milliseconds();
                    }

                    $url = $this->urls->buildUrl(
                        registry: $registry,
                        procedureUrlType: $procedureType,
                        tab: 'documents',
                        registryNumber: $registryNumber,
                    );

                    if ($url === null) {
                        continue;
                    }

                    $requestCount++;
                    $evidenceId = sprintf('b2-card-%s-%02d', $registryNumber, $requestCount);

                    try {
                        [$response, $metadata] = $this->request(registry: $registry, url: $url);
                        $consecutiveErrors = $response->serverError() ? $consecutiveErrors + 1 : 0;
                        $snapshot = $response->successful() && str_contains($response->body(), $registryNumber)
                            ? $this->documents->parse($response->body())
                            : null;
                        $documentFacts = [];

                        if ($snapshot !== null) {
                            foreach ($snapshot->documents as $document) {
                                $documentFacts[] = [
                                    'uid' => $document->externalId,
                                    'url' => $this->sanitizeUrl($this->string($document->sourceUrl, 'document.source_url'), ['uid']),
                                    'title' => $document->title,
                                    'validators' => ['etag' => null, 'last_modified' => null],
                                    'byte_sha256' => null,
                                ];
                            }
                        }

                        $captures[] = [
                            'evidence_id' => $evidenceId,
                            'registry_number' => $registryNumber,
                            'procedure_url_type' => $procedureType,
                            'request_url' => $this->sanitizeUrl($url, ['regNumber']),
                            'collected_at' => now()->toIso8601String(),
                            'http_status' => $response->status(),
                            'duration_ms' => $metadata['duration_ms'],
                            'wait_ms' => $metadata['wait_ms'],
                            'proxy_source' => $metadata['proxy_source'],
                            'response_validators' => [
                                'etag' => $response->header('ETag'),
                                'last_modified' => $response->header('Last-Modified'),
                            ],
                            'snapshot_status' => $snapshot?->status->value,
                            'documents' => $documentFacts,
                        ];
                        $evidenceIds[] = $evidenceId;

                        if ($response->status() === 429 && trim((string) $response->header('Retry-After')) === '') {
                            $stopReasons[] = 'http-429-without-retry-window';
                            $stop = true;

                            break;
                        }

                        if ($consecutiveErrors >= $maxErrors) {
                            $stopReasons[] = 'three-consecutive-transport-errors';
                            $stop = true;

                            break;
                        }

                        if ($snapshot !== null) {
                            break;
                        }
                    } catch (ConnectionException $exception) {
                        $consecutiveErrors++;
                        $captures[] = [
                            'evidence_id' => $evidenceId,
                            'registry_number' => $registryNumber,
                            'procedure_url_type' => $procedureType,
                            'request_url' => $this->sanitizeUrl($url, ['regNumber']),
                            'collected_at' => now()->toIso8601String(),
                            'transport_error' => $this->connectionFailureReason($exception)->value,
                            'documents' => [],
                        ];
                        $evidenceIds[] = $evidenceId;

                        if ($consecutiveErrors >= $maxErrors) {
                            $stopReasons[] = 'three-consecutive-transport-errors';
                            $stop = true;

                            break;
                        }
                    }
                }

                if ($stop) {
                    break;
                }
            }

            $artifact = [
                'schema_version' => 'p4-b2-live-verification/v1',
                'git_anchor' => $this->gitAnchor(),
                'authority_sha256' => $authoritySha,
                'scope_sha256' => $scopeSha,
                'pairs' => [],
                'branch' => 'insufficient_evidence',
                'revalidation_required' => true,
                'revalidation_validation' => [],
                'verdict' => [
                    'status' => 'no_complete_replacement_pairs_in_authorized_scope',
                    'request_count' => $requestCount,
                    'captures' => $captures,
                    'stop_reasons' => array_values(array_unique($stopReasons)),
                    'limitation' => 'Current attachment snapshots do not provide before/after byte evidence; absence of a counterexample is not an EIS uid immutability guarantee.',
                ],
                'evidence_ids' => $evidenceIds,
            ];

            $this->writeArtifact($artifact);
            $this->info(sprintf('B2 verification complete: %d request(s), branch=%s.', $requestCount, $artifact['branch']));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{0: Response, 1: array{duration_ms: int, wait_ms: int, proxy_source: string|null}} */
    private function request(Registry $registry, string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $lease = $this->proxies->resolve(
            scope: ProxyScope::CrawlDetail,
            target: $host,
            requirements: new ProxyRequirements(crawlerMode: CrawlerProxyMode::Default),
        );
        $platform = $registry->platform()->firstOrFail();
        $settings = $this->crawlSettings->forStage(stage: CrawlStage::Detail, platform: $platform);
        $startedAt = hrtime(true);
        $waitMs = $this->budget->acquire(host: $host, stage: CrawlStage::Detail);

        try {
            $response = $this->httpRequest(url: $url, lease: $lease, settings: $settings);
        } catch (ConnectionException $exception) {
            $this->proxies->reportOutcome($lease, ProxyOutcome::failure($this->connectionFailureReason($exception)));

            throw $exception;
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $this->proxies->reportOutcome($lease, $this->outcome(response: $response, latencyMs: $durationMs));

        return [$response, [
            'duration_ms' => $durationMs,
            'wait_ms' => $waitMs,
            'proxy_source' => $lease->source?->value,
        ]];
    }

    private function httpRequest(string $url, ProxyLease $lease, StageSettings $settings): Response
    {
        $userAgent = config('crawler.user_agent');

        if (! is_string($userAgent) || $userAgent === '') {
            throw new \UnexpectedValueException('crawler.user_agent must be a non-empty string.');
        }

        return Http::withOptions([...$lease->httpOptions(), ...$this->tlsTrust->optionsFor((string) parse_url($url, PHP_URL_HOST))])
            ->withUserAgent($userAgent)
            ->withHeaders(['Referer' => 'https://zakupki.gov.ru/'])
            ->timeout($settings->timeoutSeconds ?? 30)
            ->get($url);
    }

    private function outcome(Response $response, int $latencyMs): ProxyOutcome
    {
        return match (true) {
            $response->status() === 403 => ProxyOutcome::failure(ProxyFailureReason::Http403),
            $response->status() === 429 => ProxyOutcome::failure(ProxyFailureReason::Http429),
            $response->status() === 407 => ProxyOutcome::failure(ProxyFailureReason::ProxyAuthFailed),
            $response->status() === 200 && trim($response->body()) === '' => ProxyOutcome::failure(ProxyFailureReason::EmptyBody),
            default => ProxyOutcome::success(latencyMs: $latencyMs),
        };
    }

    private function connectionFailureReason(ConnectionException $exception): ProxyFailureReason
    {
        return match (true) {
            str_contains($exception->getMessage(), 'SSL') => ProxyFailureReason::TlsError,
            str_contains($exception->getMessage(), 'timed out'), str_contains($exception->getMessage(), 'Timeout') => ProxyFailureReason::NetworkTimeout,
            default => ProxyFailureReason::ConnectFailed,
        };
    }

    /** @return list<string> */
    private function templateTypes(Registry $registry): array
    {
        $templates = $registry->settings['card_url_templates'] ?? null;

        if (! is_array($templates) || $templates === []) {
            throw new \RuntimeException('EIS registry has no card URL templates.');
        }

        return array_values(array_filter(array_keys($templates), 'is_string'));
    }

    private function registry(): Registry
    {
        return Registry::query()
            ->where('code', 'fz44')
            ->whereHas('platform', static fn ($query) => $query->where('code', 'eis'))
            ->firstOrFail();
    }

    /** @param list<string> $allowedQueryKeys */
    private function sanitizeUrl(string $url, array $allowedQueryKeys): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \UnexpectedValueException('Evidence URL must be absolute.');
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $sanitizedQuery = [];

        foreach ($allowedQueryKeys as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $sanitizedQuery[$key] = $query[$key];
            }
        }

        $result = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).($parts['path'] ?? '');

        return $sanitizedQuery === [] ? $result : $result.'?'.http_build_query($sanitizedQuery);
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: string} */
    private function loadCarriers(): array
    {
        $input = $this->pathOption('input');
        $scopeBytes = file_get_contents($input);
        $authorityPath = dirname($input).'/P4-live-authorization.json';
        $authorityBytes = file_get_contents($authorityPath);

        if (! is_string($scopeBytes) || ! is_string($authorityBytes)) {
            throw new \RuntimeException('Cannot read P4 live authorization/scope carriers.');
        }

        $scope = $this->array(json_decode($scopeBytes, true, flags: JSON_THROW_ON_ERROR), 'scope');
        $authority = $this->array(json_decode($authorityBytes, true, flags: JSON_THROW_ON_ERROR), 'authorization');
        $scopeSha = hash('sha256', $scopeBytes);

        if (($authority['decision'] ?? null) !== 'authorized'
            || ! in_array('perform-read-only-a1-b2-live-eis-probes', (array) ($authority['authorized_actions'] ?? []), true)
            || ($authority['scope_sha256'] ?? null) !== $scopeSha
            || CarbonImmutable::parse($this->string($authority['expires_at'] ?? null, 'authorization.expires_at'))->isPast()) {
            throw new \RuntimeException('P4 live authorization is missing, expired, or does not bind the supplied scope.');
        }

        $evidencePolicy = $this->array($scope['evidence_policy'] ?? null, 'evidence_policy');

        if (($evidencePolicy['store_response_bodies'] ?? null) !== false
            || ($evidencePolicy['store_credentials'] ?? null) !== false
            || ($evidencePolicy['store_signed_query_parameters'] ?? null) !== false
            || ($evidencePolicy['sanitize_urls'] ?? null) !== true) {
            throw new \RuntimeException('Scope evidence policy is not fail-closed.');
        }

        return [$scope, hash('sha256', $authorityBytes), $scopeSha];
    }

    private function gitAnchor(): string
    {
        $process = new Process(command: ['git', 'rev-parse', 'HEAD'], cwd: base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** @param array<string, mixed> $artifact */
    private function writeArtifact(array $artifact): void
    {
        $output = $this->pathOption('output');
        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (file_put_contents($output, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write verification artifact: {$output}");
        }
    }

    private function pathOption(string $name): string
    {
        $path = $this->string($this->option($name), "--{$name}");

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @return array<string, mixed> */
    private function array(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new \UnexpectedValueException("{$key} must be an object.");
        }

        foreach (array_keys($value) as $arrayKey) {
            if (! is_string($arrayKey)) {
                throw new \UnexpectedValueException("{$key} must be an object.");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $key): array
    {
        if (! is_array($value) || $value === []) {
            throw new \UnexpectedValueException("{$key} must be a non-empty string list.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new \UnexpectedValueException("{$key} must be a non-empty string list.");
            }
        }

        return array_values($value);
    }

    private function string(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$key} must be a non-empty string.");
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
