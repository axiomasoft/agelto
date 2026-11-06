<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Diagnostics;

use App\Services\Platforms\Eis\Card\DocumentsParser;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

final readonly class DocumentUidHtmlParser
{
    /**
     * @return array{
     *     numbers: list<string>,
     *     ceiling: int,
     *     interval_ms: int,
     *     max_errors: int,
     *     a1_max_pages: int,
     *     authority_sha: string,
     *     scope_sha: string,
     * }
     */
    public function loadCarriers(string $input): array
    {
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

        $numbers = $this->stringList(
            $this->array($scope['b2'] ?? null, 'b2')['procurement_registry_numbers'] ?? null,
            'b2.procurement_registry_numbers',
        );
        $policy = $this->array($scope['request_policy'] ?? null, 'request_policy');

        return [
            'numbers' => $numbers,
            'ceiling' => $this->positiveInt($policy['total_request_ceiling'] ?? null, 'request_policy.total_request_ceiling'),
            'interval_ms' => $this->positiveInt($policy['minimum_interval_ms'] ?? null, 'request_policy.minimum_interval_ms'),
            'max_errors' => $this->positiveInt($policy['max_consecutive_transport_errors'] ?? null, 'request_policy.max_consecutive_transport_errors'),
            'a1_max_pages' => $this->positiveInt($this->array($scope['a1'] ?? null, 'a1')['max_pages'] ?? null, 'a1.max_pages'),
            'authority_sha' => hash('sha256', $authorityBytes),
            'scope_sha' => $scopeSha,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return list<string>
     */
    public function extractTemplateTypes(?array $settings): array
    {
        $templates = $settings['card_url_templates'] ?? null;

        if (! is_array($templates) || $templates === []) {
            throw new \RuntimeException('EIS registry has no card URL templates.');
        }

        return array_values(array_filter(array_keys($templates), 'is_string'));
    }

    /**
     * @return array{
     *     matched: bool,
     *     snapshot_status: string|null,
     *     documents: list<array{
     *         uid: string|null,
     *         url: string,
     *         title: string,
     *         validators: array{etag: null, last_modified: null},
     *         byte_sha256: null,
     *     }>,
     * }
     */
    public function collectFromHtml(
        DocumentsParser $documents,
        string $body,
        string $registryNumber,
        bool $successful,
    ): array {
        $snapshot = $successful && str_contains($body, $registryNumber)
            ? $documents->parse($body)
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

        return [
            'matched' => $snapshot !== null,
            'snapshot_status' => $snapshot?->status->value,
            'documents' => $documentFacts,
        ];
    }

    /**
     * @param  array{duration_ms: int, wait_ms: int, proxy_source: string|null}  $metadata
     * @param  array{etag: string, last_modified: string}  $validators
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, mixed>
     */
    public function buildCapture(
        string $evidenceId,
        string $registryNumber,
        string $procedureType,
        string $url,
        int $httpStatus,
        array $metadata,
        array $validators,
        ?string $snapshotStatus,
        array $documents,
    ): array {
        return [
            'evidence_id' => $evidenceId,
            'registry_number' => $registryNumber,
            'procedure_url_type' => $procedureType,
            'request_url' => $this->sanitizeUrl($url, ['regNumber']),
            'collected_at' => now()->toIso8601String(),
            'http_status' => $httpStatus,
            'duration_ms' => $metadata['duration_ms'],
            'wait_ms' => $metadata['wait_ms'],
            'proxy_source' => $metadata['proxy_source'],
            'response_validators' => $validators,
            'snapshot_status' => $snapshotStatus,
            'documents' => $documents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTransportErrorCapture(
        string $evidenceId,
        string $registryNumber,
        string $procedureType,
        string $url,
        string $transportError,
    ): array {
        return [
            'evidence_id' => $evidenceId,
            'registry_number' => $registryNumber,
            'procedure_url_type' => $procedureType,
            'request_url' => $this->sanitizeUrl($url, ['regNumber']),
            'collected_at' => now()->toIso8601String(),
            'transport_error' => $transportError,
            'documents' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $captures
     * @param  list<string>  $stopReasons
     * @param  list<string>  $evidenceIds
     * @return array{schema_version: string, git_anchor: string, authority_sha256: string, scope_sha256: string, pairs: list<mixed>, branch: string, revalidation_required: bool, revalidation_validation: list<mixed>, verdict: array<string, mixed>, evidence_ids: list<string>}
     */
    public function buildVerdict(
        string $authoritySha,
        string $scopeSha,
        int $requestCount,
        array $captures,
        array $stopReasons,
        array $evidenceIds,
    ): array {
        return [
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
    }

    /** @param array<string, mixed> $artifact */
    public function writeArtifact(string $output, array $artifact): void
    {
        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (file_put_contents($output, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write verification artifact: {$output}");
        }
    }

    public function resolvePathOption(mixed $rawOption, string $name): string
    {
        $path = $this->string($rawOption, "--{$name}");

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @param list<string> $allowedQueryKeys */
    public function sanitizeUrl(string $url, array $allowedQueryKeys): string
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

    public function string(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$key} must be a non-empty string.");
        }

        return $value;
    }

    private function gitAnchor(): string
    {
        $process = new Process(command: ['git', 'rev-parse', 'HEAD'], cwd: base_path());
        $process->mustRun();

        return trim($process->getOutput());
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

    private function positiveInt(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
