<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Dto\Procurement\Crawler\EisListingQueryOverrides;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Eis\CsvSourceMethod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

#[Description('Performs the owner-authorized bounded A1 EIS boundary verification')]
#[Signature('crawler:verify-fresh-boundary {--input= : Authorized P4 scope JSON} {--output= : Sanitized verification JSON}')]
final class VerifyFreshBoundary extends Command
{
    public function handle(CsvSourceMethod $source): int
    {
        try {
            [$scope, $authoritySha, $scopeSha] = $this->loadCarriers();
            $crawler = $this->crawler();
            $a1 = $this->array($scope['a1'] ?? null, 'a1');
            $window = $this->array($a1['published_window'] ?? null, 'a1.published_window');
            $from = CarbonImmutable::parse($this->string($window['from'] ?? null, 'a1.published_window.from'));
            $to = CarbonImmutable::parse($this->string($window['to'] ?? null, 'a1.published_window.to'));
            $maxPages = $this->positiveInt($a1['max_pages'] ?? null, 'a1.max_pages');
            $policy = $this->array($scope['request_policy'] ?? null, 'request_policy');
            $ceiling = $this->positiveInt($policy['total_request_ceiling'] ?? null, 'request_policy.total_request_ceiling');
            $intervalMs = $this->positiveInt($policy['minimum_interval_ms'] ?? null, 'request_policy.minimum_interval_ms');
            $maxErrors = $this->positiveInt($policy['max_consecutive_transport_errors'] ?? null, 'request_policy.max_consecutive_transport_errors');

            if ($maxPages > $ceiling) {
                throw new \UnexpectedValueException('A1 max_pages exceeds the authorized request ceiling.');
            }

            $requests = [];
            $pages = [];
            $boundaryRows = [$from->toDateString() => [], $to->toDateString() => []];
            $stopReasons = [];
            $evidenceIds = [];
            $cursor = new ListingCursor;
            $previousOldestTimestamp = null;
            $orderingConsistent = true;
            $requestCount = 0;
            $consecutiveErrors = 0;
            $page = 1;

            while ($page <= $maxPages && $requestCount < $ceiling) {
                if ($requestCount > 0) {
                    Sleep::for($intervalMs)->milliseconds();
                }

                $requestCount++;
                $collectedAt = now()->toIso8601String();
                $requestEvidenceId = sprintf('a1-request-%03d', $requestCount);

                try {
                    $batch = $source->collectListing(
                        crawler: $crawler,
                        cursor: $cursor,
                        queryOverrides: new EisListingQueryOverrides(
                            publishedFrom: $from,
                            publishedTo: $to,
                            withoutOkpd2: true,
                            retryOnEmptyResponse: false,
                            requestAttempts: 1,
                        ),
                    );
                    $consecutiveErrors = 0;
                } catch (ConnectionException $exception) {
                    $consecutiveErrors++;
                    $requests[] = [
                        'evidence_id' => $requestEvidenceId,
                        'request_number' => $requestCount,
                        'stage' => 'listing',
                        'collected_at' => $collectedAt,
                        'transport_error' => $this->transportError($exception),
                    ];
                    $evidenceIds[] = $requestEvidenceId;

                    if ($consecutiveErrors >= $maxErrors) {
                        $stopReasons[] = 'three-consecutive-transport-errors';

                        break;
                    }

                    continue;
                }

                $pageEvidenceId = sprintf('a1-page-%03d', $page);
                $timestamps = [];

                foreach ($batch->rows as $row) {
                    $publishedRaw = $row['Дата размещения'] ?? null;
                    $updatedRaw = $row['Дата обновления'] ?? null;
                    $publishedDate = is_string($publishedRaw) ? $this->sourceDate($publishedRaw) : null;
                    $updatedTimestamp = is_string($updatedRaw) ? $this->sourceTimestamp($updatedRaw) : null;

                    if ($updatedTimestamp !== null) {
                        $timestamps[] = $updatedTimestamp;
                    }

                    if ($publishedDate !== null && array_key_exists($publishedDate, $boundaryRows)) {
                        $boundaryRows[$publishedDate][] = [
                            'page_sequence' => $page,
                            'registry_number' => ltrim((string) ($row['Реестровый номер закупки'] ?? ''), '№'),
                            'published_at_source' => $publishedRaw,
                            'updated_at_source' => $updatedRaw,
                        ];
                    }
                }

                rsort($timestamps, SORT_STRING);
                $newest = $timestamps[0] ?? null;
                $oldest = $timestamps === [] ? null : $timestamps[array_key_last($timestamps)];

                if ($previousOldestTimestamp !== null && $newest !== null && $newest > $previousOldestTimestamp) {
                    $orderingConsistent = false;
                }

                $previousOldestTimestamp = $oldest ?? $previousOldestTimestamp;
                $requests[] = [
                    'evidence_id' => $requestEvidenceId,
                    'request_number' => $requestCount,
                    'stage' => 'listing',
                    'collected_at' => $collectedAt,
                    'http_status' => $batch->httpStatus,
                    'duration_ms' => $batch->durationMs,
                    'wait_ms' => $batch->waitMs,
                    'proxy_source' => $batch->proxySource?->value,
                ];
                $pages[] = [
                    'evidence_id' => $pageEvidenceId,
                    'page_sequence' => $page,
                    'block_from' => $cursor->blockFrom,
                    'rows_total' => $batch->rowsTotal,
                    'newest_updated_at_source' => $newest,
                    'oldest_updated_at_source' => $oldest,
                    'dedup_signature_sha256' => $batch->dedupSignature === null ? null : hash('sha256', $batch->dedupSignature),
                ];
                array_push($evidenceIds, $requestEvidenceId, $pageEvidenceId);

                if ($batch->rowsTotal === 0) {
                    $stopReasons[] = 'source_exhausted';

                    break;
                }

                if ($cursor->lastDedupSignature !== null && $batch->dedupSignature === $cursor->lastDedupSignature) {
                    $stopReasons[] = 'dedup_signature_repeat';

                    break;
                }

                $cursor = $cursor->afterFreshBlock(
                    blockSize: $this->positiveInt(config('crawler.eis.block_size', 500), 'crawler.eis.block_size'),
                    dedupSignature: $batch->dedupSignature,
                );
                $page++;
            }

            if ($stopReasons === []) {
                $stopReasons[] = $page > $maxPages ? 'authorized_max_pages_reached' : 'request-ceiling-reached';
            }

            $boundaryDays = [];

            foreach ($boundaryRows as $date => $rows) {
                $pageSequences = array_values(array_unique(array_column($rows, 'page_sequence')));
                $boundaryDays[] = [
                    'date' => $date,
                    'row_count' => count($rows),
                    'page_sequences' => $pageSequences,
                    'rows' => $rows,
                ];
            }

            $multiPageBoundaryObserved = collect($boundaryDays)
                ->contains(static fn (array $day): bool => count($day['page_sequences']) > 1);
            $artifact = [
                'schema_version' => 'p4-a1-live-verification/v1',
                'git_anchor' => $this->gitAnchor(),
                'authority_sha256' => $authoritySha,
                'scope_sha256' => $scopeSha,
                'requests' => $requests,
                'pages' => $pages,
                'boundary_days' => $boundaryDays,
                'stop_reasons' => $stopReasons,
                'verdict' => [
                    'status' => $multiPageBoundaryObserved && $orderingConsistent
                        ? 'multi_page_boundary_ordering_observed'
                        : 'insufficient_live_boundary_evidence',
                    'ordering_consistent' => $orderingConsistent,
                    'multi_page_boundary_observed' => $multiPageBoundaryObserved,
                    'limitation' => 'Finite owner-authorized window; this evidence does not prove universal EIS ordering.',
                ],
                'evidence_ids' => $evidenceIds,
            ];

            $this->writeArtifact($artifact);
            $this->info(sprintf('A1 verification complete: %d request(s), %s.', count($requests), $artifact['verdict']['status']));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: string} */
    private function loadCarriers(): array
    {
        $input = $this->pathOption('input');
        $scopeBytes = file_get_contents($input);

        if (! is_string($scopeBytes)) {
            throw new \RuntimeException("Cannot read scope carrier: {$input}");
        }

        $authorityPath = dirname($input).'/P4-live-authorization.json';
        $authorityBytes = file_get_contents($authorityPath);

        if (! is_string($authorityBytes)) {
            throw new \RuntimeException("Cannot read authorization carrier: {$authorityPath}");
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

    private function crawler(): Crawler
    {
        return Crawler::query()
            ->where('mode', CrawlerMode::Fresh)
            ->whereHas('registry', static fn ($query) => $query
                ->where('code', 'fz44')
                ->whereHas('platform', static fn ($query) => $query->where('code', 'eis')))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function sourceDate(string $value): ?string
    {
        return preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($value), $matches) === 1
            ? "{$matches[3]}-{$matches[2]}-{$matches[1]}"
            : null;
    }

    private function sourceTimestamp(string $value): ?string
    {
        $value = trim($value);

        foreach (['d.m.Y H:i:s', 'd.m.Y'] as $format) {
            try {
                $timestamp = CarbonImmutable::createFromFormat($format, $value, 'Europe/Moscow');

                if ($timestamp !== null) {
                    return $timestamp->toIso8601String();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function transportError(ConnectionException $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'SSL') => 'tls_error',
            str_contains($exception->getMessage(), 'timed out'), str_contains($exception->getMessage(), 'Timeout') => 'network_timeout',
            default => 'connect_failed',
        };
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
