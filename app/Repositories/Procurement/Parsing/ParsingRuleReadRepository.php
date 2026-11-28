<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Parsing;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Parsing\ParsingRuleEvent;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Репозиторий чтения версионированных правил парсинга и указателей активных версий.
 */
final class ParsingRuleReadRepository
{
    public function findRuleSet(
        int $platformId,
        StageCode $stage,
        ?string $registryCode = null,
        string $scopeKey = 'default',
    ): ?ParsingRuleSet {
        if ($registryCode !== null) {
            $exact = ParsingRuleSet::query()
                ->where('platform_id', $platformId)
                ->where('stage', $stage->value)
                ->where('registry_code', $registryCode)
                ->where('scope_key', $scopeKey)
                ->first();

            if ($exact !== null) {
                return $exact;
            }
        }

        return ParsingRuleSet::query()
            ->where('platform_id', $platformId)
            ->where('stage', $stage->value)
            ->whereNull('registry_code')
            ->where('scope_key', $scopeKey)
            ->first();
    }

    /**
     * Быстрый 1-row indexed SELECT активного указателя правил.
     * Для `status_refresh` при отсутствии строки `prc_parsing_rule_sets` выполняет fallback на `detail`.
     *
     * @return array{
     *     rule_set_id: int,
     *     is_enabled: bool,
     *     active_version_id: ?int,
     *     version: ?int,
     *     schema_id: ?string,
     *     schema_version: ?int,
     *     rules_hash_hex: ?string,
     *     registry_code: ?string,
     *     scope_key: string,
     *     auto_heal_mode: string,
     * }|null
     */
    public function findActivePointer(
        int $platformId,
        StageCode $stage,
        ?string $registryCode = null,
        string $scopeKey = 'default',
    ): ?array {
        $pointer = $this->selectPointer($platformId, $stage, $registryCode, $scopeKey);

        if ($pointer === null && $stage === StageCode::StatusRefresh) {
            return $this->selectPointer($platformId, StageCode::Detail, $registryCode, $scopeKey);
        }

        return $pointer;
    }

    public function findVersionPayloadById(int $versionId): ?ParsingRuleVersion
    {
        return ParsingRuleVersion::query()->whereKey($versionId)->first();
    }

    public function findVersionByNumber(int $ruleSetId, int $version): ?ParsingRuleVersion
    {
        return ParsingRuleVersion::query()
            ->where('rule_set_id', $ruleSetId)
            ->where('version', $version)
            ->first();
    }

    public function findByHash(int $ruleSetId, ContentHash $hash): ?ParsingRuleVersion
    {
        return ParsingRuleVersion::query()
            ->where('rule_set_id', $ruleSetId)
            ->whereRaw("rules_hash = decode(?, 'hex')", [$hash->hex])
            ->first();
    }

    /**
     * @return Collection<int, ParsingRuleVersion>
     */
    public function listVersions(int $ruleSetId): Collection
    {
        return ParsingRuleVersion::query()
            ->where('rule_set_id', $ruleSetId)
            ->orderByDesc('version')
            ->get();
    }

    /**
     * @return Collection<int, ParsingRuleEvent>
     */
    public function listEvents(int $ruleSetId, int $limit = 50): Collection
    {
        return ParsingRuleEvent::query()
            ->where('rule_set_id', $ruleSetId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{
     *     rule_set_id: int,
     *     is_enabled: bool,
     *     active_version_id: ?int,
     *     version: ?int,
     *     schema_id: ?string,
     *     schema_version: ?int,
     *     rules_hash_hex: ?string,
     *     registry_code: ?string,
     *     scope_key: string,
     *     auto_heal_mode: string,
     * }|null
     */
    private function selectPointer(
        int $platformId,
        StageCode $stage,
        ?string $registryCode,
        string $scopeKey,
    ): ?array {
        $bindings = [$platformId, $stage->value, $scopeKey];
        $registryClause = 'rs.registry_code IS NULL';
        $orderClause = '';

        if ($registryCode !== null) {
            $registryClause = '(rs.registry_code = ? OR rs.registry_code IS NULL)';
            $orderClause = 'ORDER BY (rs.registry_code IS NULL) ASC';
            $bindings[] = $registryCode;
        }

        $row = DB::selectOne(
            "SELECT
                rs.id AS rule_set_id,
                rs.is_enabled,
                rs.active_version_id,
                rs.registry_code,
                rs.scope_key,
                rs.auto_heal_mode,
                rv.version,
                rv.schema_id,
                rv.schema_version,
                encode(rv.rules_hash, 'hex') AS rules_hash_hex
            FROM prc_parsing_rule_sets rs
            LEFT JOIN prc_parsing_rule_versions rv ON rv.id = rs.active_version_id
            WHERE rs.platform_id = ?
              AND rs.stage = ?
              AND rs.scope_key = ?
              AND {$registryClause}
            {$orderClause}
            LIMIT 1",
            $bindings,
        );

        if (! is_object($row)) {
            return null;
        }

        $vars = get_object_vars($row);
        $ruleSetId = $vars['rule_set_id'] ?? null;
        $isEnabled = $vars['is_enabled'] ?? null;
        $activeVersionId = $vars['active_version_id'] ?? null;
        $version = $vars['version'] ?? null;
        $schemaId = $vars['schema_id'] ?? null;
        $schemaVersion = $vars['schema_version'] ?? null;
        $rulesHashHex = $vars['rules_hash_hex'] ?? null;
        $rowRegistryCode = $vars['registry_code'] ?? null;
        $rowScopeKey = $vars['scope_key'] ?? null;
        $autoHealMode = $vars['auto_heal_mode'] ?? null;

        if (! is_int($ruleSetId) && ! is_string($ruleSetId)) {
            throw new UnexpectedValueException('Invalid rule_set_id in active pointer query.');
        }

        return [
            'rule_set_id' => (int) $ruleSetId,
            'is_enabled' => (bool) $isEnabled,
            'active_version_id' => is_int($activeVersionId) || is_string($activeVersionId) ? (int) $activeVersionId : null,
            'version' => is_int($version) || is_string($version) ? (int) $version : null,
            'schema_id' => is_string($schemaId) ? $schemaId : null,
            'schema_version' => is_int($schemaVersion) || is_string($schemaVersion) ? (int) $schemaVersion : null,
            'rules_hash_hex' => is_string($rulesHashHex) ? strtolower($rulesHashHex) : null,
            'registry_code' => is_string($rowRegistryCode) ? $rowRegistryCode : null,
            'scope_key' => is_string($rowScopeKey) ? $rowScopeKey : $scopeKey,
            'auto_heal_mode' => is_string($autoHealMode) ? $autoHealMode : AutoHealMode::Propose->value,
        ];
    }
}
