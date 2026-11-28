<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Parsing;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Parsing\ParsingRuleEvent;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Транзакционный репозиторий записи версионированных правил парсинга.
 */
final class ParsingRuleStoreRepository
{
    public function ensureRuleSet(
        int $platformId,
        StageCode $stage,
        ?string $registryCode = null,
        string $scopeKey = 'default',
        AutoHealMode $autoHealMode = AutoHealMode::Propose,
        ?string $description = null,
    ): ParsingRuleSet {
        $uuid = (string) Str::uuid();

        DB::insert(
            "INSERT INTO prc_parsing_rule_sets (
                uuid,
                platform_id,
                stage,
                registry_code,
                scope_key,
                active_version_id,
                current_version_number,
                auto_heal_mode,
                description,
                is_enabled,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, NULL, 0, ?, ?, true, clock_timestamp(), clock_timestamp()
            )
            ON CONFLICT (platform_id, stage, COALESCE(registry_code, ''), scope_key) DO NOTHING",
            [
                $uuid,
                $platformId,
                $stage->value,
                $registryCode,
                $scopeKey,
                $autoHealMode->value,
                $description,
            ],
        );

        $query = ParsingRuleSet::query()
            ->where('platform_id', $platformId)
            ->where('stage', $stage->value)
            ->where('scope_key', $scopeKey);

        if ($registryCode === null) {
            $query->whereNull('registry_code');
        } else {
            $query->where('registry_code', $registryCode);
        }

        return $query->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $rulesJson
     * @param  array<string, mixed>|null  $validationReport
     */
    public function createVersion(
        int $ruleSetId,
        string $schemaId,
        int $schemaVersion,
        array $rulesJson,
        ContentHash $rulesHash,
        ParsingRuleSource $source,
        string $changeSummary,
        ParsingRuleVersionStatus $status = ParsingRuleVersionStatus::Draft,
        ?array $validationReport = null,
        ?int $parentVersionId = null,
        ?int $createdBy = null,
        ParsingRuleActorType $actorType = ParsingRuleActorType::System,
        ?int $actorId = null,
        ?string $reason = null,
    ): ParsingRuleVersion {
        if ($status !== ParsingRuleVersionStatus::Draft && $status !== ParsingRuleVersionStatus::Validated) {
            throw new InvalidArgumentException('New parsing rule version must be created in draft or validated status.');
        }

        return DB::transaction(function () use (
            $ruleSetId,
            $schemaId,
            $schemaVersion,
            $rulesJson,
            $rulesHash,
            $source,
            $changeSummary,
            $status,
            $validationReport,
            $parentVersionId,
            $createdBy,
            $actorType,
            $actorId,
            $reason,
        ): ParsingRuleVersion {
            $ruleSet = $this->lockRuleSet($ruleSetId);
            $now = $this->clockTimestamp();
            $nextVersion = $ruleSet->current_version_number + 1;

            $version = ParsingRuleVersion::query()->create([
                'uuid' => (string) Str::uuid(),
                'rule_set_id' => $ruleSet->id,
                'version' => $nextVersion,
                'schema_id' => $schemaId,
                'schema_version' => $schemaVersion,
                'status' => $status,
                'rules_json' => $rulesJson,
                'rules_hash' => $rulesHash->hex,
                'source' => $source,
                'change_summary' => $changeSummary,
                'validation_report' => $validationReport,
                'parent_version_id' => $parentVersionId,
                'created_by' => $createdBy,
                'activated_by' => null,
                'activated_at' => null,
                'deactivated_at' => null,
                'created_at' => $now,
            ]);

            DB::update(
                'UPDATE prc_parsing_rule_sets SET current_version_number = ?, updated_at = clock_timestamp() WHERE id = ?',
                [$nextVersion, $ruleSet->id],
            );

            $eventReason = $reason ?? $changeSummary;

            $this->insertEvent(
                ruleSetId: $ruleSet->id,
                ruleVersionId: $version->id,
                eventType: ParsingRuleEventType::Created,
                actorType: $actorType,
                actorId: $actorId,
                reason: $eventReason,
                metadata: ['version' => $nextVersion, 'source' => $source->value],
                occurredAt: $now,
            );

            if ($status === ParsingRuleVersionStatus::Validated) {
                $this->insertEvent(
                    ruleSetId: $ruleSet->id,
                    ruleVersionId: $version->id,
                    eventType: ParsingRuleEventType::Validated,
                    actorType: $actorType,
                    actorId: $actorId,
                    reason: $eventReason,
                    metadata: ['version' => $nextVersion],
                    occurredAt: $now,
                );
            }

            return $version->refresh();
        });
    }

    public function activateVersion(
        int $ruleSetId,
        int $versionId,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
        bool $allowDraft = false,
    ): ParsingRuleVersion {
        return DB::transaction(function () use (
            $ruleSetId,
            $versionId,
            $actorType,
            $actorId,
            $reason,
            $allowDraft,
        ): ParsingRuleVersion {
            $ruleSet = $this->lockRuleSet($ruleSetId);

            $target = ParsingRuleVersion::query()
                ->where('rule_set_id', $ruleSet->id)
                ->whereKey($versionId)
                ->lockForUpdate()
                ->firstOrFail();

            $allowedStatuses = $allowDraft
                ? [ParsingRuleVersionStatus::Validated, ParsingRuleVersionStatus::Draft]
                : [ParsingRuleVersionStatus::Validated];

            if (! in_array($target->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot activate parsing rule version #%d in status "%s".',
                    $target->id,
                    $target->status->value,
                ));
            }

            $now = $this->clockTimestamp();

            $this->supersedeActiveVersions($ruleSet->id, $now);

            DB::update(
                'UPDATE prc_parsing_rule_versions SET status = ?, activated_by = ?, activated_at = ? WHERE id = ?',
                [ParsingRuleVersionStatus::Active->value, $actorId, $now->format('Y-m-d H:i:s.uP'), $target->id],
            );

            DB::update(
                'UPDATE prc_parsing_rule_sets SET active_version_id = ?, updated_at = clock_timestamp() WHERE id = ?',
                [$target->id, $ruleSet->id],
            );

            $this->insertEvent(
                ruleSetId: $ruleSet->id,
                ruleVersionId: $target->id,
                eventType: ParsingRuleEventType::Activated,
                actorType: $actorType,
                actorId: $actorId,
                reason: $reason,
                metadata: ['version' => $target->version],
                occurredAt: $now,
            );

            return $target->refresh();
        });
    }

    public function rollbackToVersion(
        int $ruleSetId,
        int $targetVersionId,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
    ): ParsingRuleVersion {
        return DB::transaction(function () use (
            $ruleSetId,
            $targetVersionId,
            $actorType,
            $actorId,
            $reason,
        ): ParsingRuleVersion {
            $ruleSet = $this->lockRuleSet($ruleSetId);

            $target = ParsingRuleVersion::query()
                ->where('rule_set_id', $ruleSet->id)
                ->whereKey($targetVersionId)
                ->firstOrFail();

            if (! in_array($target->status, [ParsingRuleVersionStatus::Active, ParsingRuleVersionStatus::Superseded], true)) {
                throw new \DomainException(sprintf(
                    'Cannot rollback to version v%d with status "%s". Only active or superseded versions are allowed.',
                    $target->version,
                    $target->status->value,
                ));
            }

            $now = $this->clockTimestamp();
            $nextVersion = $ruleSet->current_version_number + 1;

            $this->supersedeActiveVersions($ruleSet->id, $now);

            $newVersion = ParsingRuleVersion::query()->create([
                'uuid' => (string) Str::uuid(),
                'rule_set_id' => $ruleSet->id,
                'version' => $nextVersion,
                'schema_id' => $target->schema_id,
                'schema_version' => $target->schema_version,
                'status' => ParsingRuleVersionStatus::Active,
                'rules_json' => $target->rules_json,
                'rules_hash' => $target->rules_hash,
                'source' => ParsingRuleSource::Rollback,
                'change_summary' => sprintf('Rollback to v%d: %s', $target->version, $reason),
                'validation_report' => $target->validation_report,
                'parent_version_id' => $target->id,
                'created_by' => $actorId,
                'activated_by' => $actorId,
                'activated_at' => $now,
                'deactivated_at' => null,
                'created_at' => $now,
            ]);

            DB::update(
                'UPDATE prc_parsing_rule_sets SET active_version_id = ?, current_version_number = ?, updated_at = clock_timestamp() WHERE id = ?',
                [$newVersion->id, $nextVersion, $ruleSet->id],
            );

            $this->insertEvent(
                ruleSetId: $ruleSet->id,
                ruleVersionId: $newVersion->id,
                eventType: ParsingRuleEventType::RolledBack,
                actorType: $actorType,
                actorId: $actorId,
                reason: $reason,
                metadata: [
                    'version' => $nextVersion,
                    'target_version_id' => $target->id,
                    'target_version' => $target->version,
                ],
                occurredAt: $now,
            );

            return $newVersion->refresh();
        });
    }

    public function setEnabled(
        int $ruleSetId,
        bool $enabled,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
    ): ParsingRuleSet {
        return DB::transaction(function () use ($ruleSetId, $enabled, $actorType, $actorId, $reason): ParsingRuleSet {
            $ruleSet = $this->lockRuleSet($ruleSetId);
            $now = $this->clockTimestamp();

            DB::update(
                'UPDATE prc_parsing_rule_sets SET is_enabled = ?, updated_at = clock_timestamp() WHERE id = ?',
                [$enabled, $ruleSet->id],
            );

            $this->insertEvent(
                ruleSetId: $ruleSet->id,
                ruleVersionId: $ruleSet->active_version_id,
                eventType: ParsingRuleEventType::Archived,
                actorType: $actorType,
                actorId: $actorId,
                reason: $reason,
                metadata: ['is_enabled' => $enabled],
                occurredAt: $now,
            );

            return $ruleSet->refresh();
        });
    }

    public function setAutoHealMode(
        int $ruleSetId,
        AutoHealMode $mode,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
    ): ParsingRuleSet {
        return DB::transaction(function () use ($ruleSetId, $mode, $actorType, $actorId, $reason): ParsingRuleSet {
            $ruleSet = $this->lockRuleSet($ruleSetId);
            $now = $this->clockTimestamp();

            DB::update(
                'UPDATE prc_parsing_rule_sets SET auto_heal_mode = ?, updated_at = clock_timestamp() WHERE id = ?',
                [$mode->value, $ruleSet->id],
            );

            $this->insertEvent(
                ruleSetId: $ruleSet->id,
                ruleVersionId: $ruleSet->active_version_id,
                eventType: ParsingRuleEventType::Archived,
                actorType: $actorType,
                actorId: $actorId,
                reason: $reason,
                metadata: ['auto_heal_mode' => $mode->value],
                occurredAt: $now,
            );

            return $ruleSet->refresh();
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordEvent(
        int $ruleSetId,
        ?int $ruleVersionId,
        ParsingRuleEventType $eventType,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
        ?array $metadata = null,
    ): ParsingRuleEvent {
        return DB::transaction(function () use (
            $ruleSetId,
            $ruleVersionId,
            $eventType,
            $actorType,
            $actorId,
            $reason,
            $metadata,
        ): ParsingRuleEvent {
            $this->lockRuleSet($ruleSetId);

            return $this->insertEvent(
                ruleSetId: $ruleSetId,
                ruleVersionId: $ruleVersionId,
                eventType: $eventType,
                actorType: $actorType,
                actorId: $actorId,
                reason: $reason,
                metadata: $metadata,
                occurredAt: $this->clockTimestamp(),
            );
        });
    }

    private function lockRuleSet(int $ruleSetId): ParsingRuleSet
    {
        return ParsingRuleSet::query()
            ->whereKey($ruleSetId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function supersedeActiveVersions(int $ruleSetId, CarbonImmutable $now): void
    {
        DB::update(
            'UPDATE prc_parsing_rule_versions SET status = ?, deactivated_at = ? WHERE rule_set_id = ? AND status = ?',
            [
                ParsingRuleVersionStatus::Superseded->value,
                $now->format('Y-m-d H:i:s.uP'),
                $ruleSetId,
                ParsingRuleVersionStatus::Active->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function insertEvent(
        int $ruleSetId,
        ?int $ruleVersionId,
        ParsingRuleEventType $eventType,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
        ?array $metadata,
        CarbonImmutable $occurredAt,
    ): ParsingRuleEvent {
        return ParsingRuleEvent::query()->create([
            'rule_set_id' => $ruleSetId,
            'rule_version_id' => $ruleVersionId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function clockTimestamp(): CarbonImmutable
    {
        $row = DB::selectOne('SELECT clock_timestamp() AS now');
        if (! is_object($row)) {
            throw new UnexpectedValueException('Failed to read PostgreSQL clock_timestamp().');
        }

        $now = get_object_vars($row)['now'] ?? null;
        if (! is_string($now)) {
            throw new UnexpectedValueException('Failed to read PostgreSQL clock_timestamp().');
        }

        return CarbonImmutable::parse($now);
    }
}
