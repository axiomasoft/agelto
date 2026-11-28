<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing;

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Validation\RuleValidationResult;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Parsing\InvalidParsingRuleSetException;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Models\Procurement\Parsing\ParsingRuleEvent;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use App\Repositories\Procurement\Parsing\ParsingRuleStoreRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Parsing\Validation\ParsingRuleValidator;
use App\Services\Procurement\Platform\PlatformRegistry;

/**
 * Фасад управления жизненным циклом версионированных правил парсинга.
 */
final class ParsingRuleManager
{
    public function __construct(
        private readonly ParsingRuleValidator $validator,
        private readonly ParsingRuleStoreRepository $store,
        private readonly ?PlatformRegistry $platformRegistry = null,
    ) {}

    /**
     * @param  array<string, mixed>  $rules
     */
    public function validate(
        StageCode $stage,
        array $rules,
        int $schemaVersion = 1,
        ?PlatformDefinition $definition = null,
        ?int $platformId = null,
        ?string $platformCode = null,
    ): RuleValidationResult {
        $resolvedDefinition = $this->resolveDefinition($definition, $platformId, $platformCode);

        return $this->validator->validate($stage, $rules, $schemaVersion, $resolvedDefinition);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function createDraft(
        int $platformId,
        StageCode $stage,
        array $rules,
        string $changeSummary,
        ?string $registryCode = null,
        string $scopeKey = 'default',
        int $schemaVersion = 1,
        ParsingRuleSource $source = ParsingRuleSource::Manual,
        ?int $createdBy = null,
        ParsingRuleActorType $actorType = ParsingRuleActorType::System,
        ?int $actorId = null,
        ?PlatformDefinition $definition = null,
        AutoHealMode $autoHealMode = AutoHealMode::Propose,
    ): ParsingRuleVersion {
        $resolvedDefinition = $this->resolveDefinition($definition, $platformId);
        $validation = $this->validator->validate($stage, $rules, $schemaVersion, $resolvedDefinition);
        if (! $validation->valid) {
            throw new InvalidParsingRuleSetException($validation);
        }

        $dto = $this->validator->hydrateDto($stage, $rules);
        $canonicalRules = $dto->toArray();
        $rulesHash = $this->validator->computeCanonicalHash($stage, $canonicalRules);

        $ruleSet = $this->store->ensureRuleSet(
            platformId: $platformId,
            stage: $stage,
            registryCode: $registryCode,
            scopeKey: $scopeKey,
            autoHealMode: $autoHealMode,
        );

        return $this->store->createVersion(
            ruleSetId: $ruleSet->id,
            schemaId: $validation->schemaId,
            schemaVersion: $validation->schemaVersion,
            rulesJson: $canonicalRules,
            rulesHash: $rulesHash,
            source: $source,
            changeSummary: $changeSummary,
            status: ParsingRuleVersionStatus::Draft,
            validationReport: $validation->toArray(),
            createdBy: $createdBy,
            actorType: $actorType,
            actorId: $actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function publish(
        int $platformId,
        StageCode $stage,
        array $rules,
        string $changeSummary,
        ParsingRuleActorType $actorType = ParsingRuleActorType::System,
        ?int $actorId = null,
        ?string $registryCode = null,
        string $scopeKey = 'default',
        int $schemaVersion = 1,
        ParsingRuleSource $source = ParsingRuleSource::Manual,
        ?PlatformDefinition $definition = null,
        AutoHealMode $autoHealMode = AutoHealMode::Propose,
    ): ParsingRuleVersion {
        $resolvedDefinition = $this->resolveDefinition($definition, $platformId);
        $validation = $this->validator->validate($stage, $rules, $schemaVersion, $resolvedDefinition);
        if (! $validation->valid) {
            throw new InvalidParsingRuleSetException($validation);
        }

        $dto = $this->validator->hydrateDto($stage, $rules);
        $canonicalRules = $dto->toArray();
        $rulesHash = $this->validator->computeCanonicalHash($stage, $canonicalRules);

        $ruleSet = $this->store->ensureRuleSet(
            platformId: $platformId,
            stage: $stage,
            registryCode: $registryCode,
            scopeKey: $scopeKey,
            autoHealMode: $autoHealMode,
        );

        $version = $this->store->createVersion(
            ruleSetId: $ruleSet->id,
            schemaId: $validation->schemaId,
            schemaVersion: $validation->schemaVersion,
            rulesJson: $canonicalRules,
            rulesHash: $rulesHash,
            source: $source,
            changeSummary: $changeSummary,
            status: ParsingRuleVersionStatus::Validated,
            validationReport: $validation->toArray(),
            createdBy: $actorId,
            actorType: $actorType,
            actorId: $actorId,
        );

        return $this->store->activateVersion(
            ruleSetId: $ruleSet->id,
            versionId: $version->id,
            actorType: $actorType,
            actorId: $actorId,
            reason: $changeSummary,
        );
    }

    public function activate(
        int $ruleSetId,
        int $versionId,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
        bool $allowDraft = false,
    ): ParsingRuleVersion {
        return $this->store->activateVersion(
            ruleSetId: $ruleSetId,
            versionId: $versionId,
            actorType: $actorType,
            actorId: $actorId,
            reason: $reason,
            allowDraft: $allowDraft,
        );
    }

    public function rollback(
        int $ruleSetId,
        int $targetVersionId,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        string $reason,
    ): ParsingRuleVersion {
        return $this->store->rollbackToVersion(
            ruleSetId: $ruleSetId,
            targetVersionId: $targetVersionId,
            actorType: $actorType,
            actorId: $actorId,
            reason: $reason,
        );
    }

    public function recordDriftDetected(
        int $ruleSetId,
        ?int $ruleVersionId,
        DriftSignal $signal,
        ParsingRuleActorType $actorType = ParsingRuleActorType::System,
        ?int $actorId = null,
    ): ParsingRuleEvent {
        return $this->store->recordEvent(
            ruleSetId: $ruleSetId,
            ruleVersionId: $ruleVersionId,
            eventType: ParsingRuleEventType::DriftDetected,
            actorType: $actorType,
            actorId: $actorId,
            reason: sprintf('[%s] %s', $signal->signalCode, $signal->fieldOrSelector),
            metadata: [
                'signal_code' => $signal->signalCode,
                'stage' => $signal->stage->value,
                'field_or_selector' => $signal->fieldOrSelector,
                'expected' => $signal->expected,
                'actual' => $signal->actual,
                'severity' => $signal->severity,
            ],
        );
    }

    private function resolveDefinition(
        ?PlatformDefinition $definition,
        ?int $platformId = null,
        ?string $platformCode = null,
    ): ?PlatformDefinition {
        if ($definition !== null) {
            return $definition;
        }

        if ($platformCode === null && $platformId !== null) {
            $platformCode = LedgerRow::one('SELECT code FROM prc_platforms WHERE id = ?', [$platformId])?->string('code');
        }

        if ($platformCode === null || trim($platformCode) === '') {
            return null;
        }

        $registry = $this->platformRegistry ?? app(PlatformRegistry::class);

        try {
            return $registry->get(new PlatformCode($platformCode));
        } catch (InvalidPlatformDefinition) {
            return null;
        }
    }
}
