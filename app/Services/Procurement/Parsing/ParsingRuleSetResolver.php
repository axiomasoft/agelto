<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing;

use App\Dto\Procurement\Parsing\Extraction\ResolvedParsingRuleSet;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Parsing\ParsingRuleSetDisabledException;
use App\Exceptions\Procurement\Parsing\ParsingRuleSetUnavailableException;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;

/**
 * Резолвер активного набора правил парсинга с fail-closed семантикой и LRU-кэшем.
 */
final class ParsingRuleSetResolver
{
    private const int MAX_CACHE_ENTRIES = 32;

    /** @var array<string, ResolvedParsingRuleSet> */
    private array $cache = [];

    public function __construct(
        private readonly ParsingRuleReadRepository $readRepository,
    ) {}

    public function resolve(
        Platform $platform,
        StageCode $stage,
        ?string $registryCode = null,
        string $scopeKey = 'default',
    ): ?ResolvedParsingRuleSet {
        return $this->resolveForPlatformId(
            platformId: (int) $platform->id,
            platformCode: $platform->code,
            stage: $stage,
            registryCode: $registryCode,
            scopeKey: $scopeKey,
        );
    }

    public function resolveForPlatformId(
        int $platformId,
        string $platformCode,
        StageCode $stage,
        ?string $registryCode = null,
        string $scopeKey = 'default',
    ): ?ResolvedParsingRuleSet {
        $pointer = $this->readRepository->findActivePointer(
            platformId: $platformId,
            stage: $stage,
            registryCode: $registryCode,
            scopeKey: $scopeKey,
        );

        if ($pointer === null) {
            return null;
        }

        if ($pointer['is_enabled'] === false) {
            throw new ParsingRuleSetDisabledException(sprintf(
                'Parsing rule set #%d (%s/%s) is disabled.',
                $pointer['rule_set_id'],
                $platformCode,
                $stage->value,
            ));
        }

        $versionId = $pointer['active_version_id'];
        $rulesHashHex = $pointer['rules_hash_hex'];

        if ($versionId === null || $rulesHashHex === null) {
            throw new ParsingRuleSetUnavailableException(sprintf(
                'Parsing rule set #%d (%s/%s) has no active version.',
                $pointer['rule_set_id'],
                $platformCode,
                $stage->value,
            ));
        }

        $autoHealMode = AutoHealMode::tryFrom($pointer['auto_heal_mode']) ?? AutoHealMode::Propose;
        $cacheKey = $versionId.':'.$rulesHashHex.':'.$autoHealMode->value;

        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            unset($this->cache[$cacheKey]);
            $this->cache[$cacheKey] = $cached;

            return $cached;
        }

        $version = $this->readRepository->findVersionPayloadById($versionId);
        if ($version === null) {
            throw new ParsingRuleSetUnavailableException(sprintf(
                'Active parsing rule version #%d could not be loaded.',
                $versionId,
            ));
        }

        $resolved = new ResolvedParsingRuleSet(
            ruleSetId: $pointer['rule_set_id'],
            ruleVersionId: $version->id,
            version: $version->version,
            platformCode: $platformCode,
            stage: $stage,
            registryCode: $pointer['registry_code'],
            scopeKey: $pointer['scope_key'],
            schemaId: $version->schema_id,
            schemaVersion: $version->schema_version,
            rulesHashHex: $version->rules_hash,
            autoHealMode: $autoHealMode,
            rules: $version->rules,
        );

        if (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            unset($this->cache[(string) array_key_first($this->cache)]);
        }

        $this->cache[$cacheKey] = $resolved;

        return $resolved;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
