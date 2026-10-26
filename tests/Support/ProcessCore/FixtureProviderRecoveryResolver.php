<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\ReceiptRef;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ProviderOperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\RecoveryReconciler;

/**
 * Fixture purpose resolver P1.11 (documents-ai#s08 на локальных фактах): findings задаёт тест,
 * реальных provider send/reserve здесь нет. Считает settlement и отзывы send authorization, чтобы
 * tests утверждали «0 новых send/reserve» и «settlement ровно один». Никогда не регистрируется в
 * приложении; настоящий mapping на Llm ledger подключает P3.8–P3.14.
 */
final class FixtureProviderRecoveryResolver implements ProviderRecoveryResolver
{
    /** @var array<string, ProviderRecoveryPlan> */
    private array $findings = [];

    /** @var list<string> physical_call_id:mode каждого settlement вызова */
    public array $settlements = [];

    /** @var list<string> */
    public array $revoked = [];

    public int $inspections = 0;

    /** Недоступное хранилище receipt: inspect бросает DependencyUnavailable('storage'). */
    public bool $storageDown = false;

    /** Hook между pre-read и locked tx: моделирует cancel/pause, пришедшие после проверки receipt. */
    public ?\Closure $afterInspect = null;

    /** Резолвер на время теста подключается к singleton reconciler процесса. */
    public static function install(): self
    {
        $resolver = new self;
        app(RecoveryReconciler::class)->bindProviderResolver($resolver);

        return $resolver;
    }

    public function finding(WorkId $work, ProviderRecoveryPlan $plan): void
    {
        $this->findings[$work->value->value] = $plan;
    }

    public function inspect(WorkId $workId, ?ProviderRecoveryPlan $pinned): ?ProviderRecoveryPlan
    {
        $this->inspections++;
        if ($this->storageDown) {
            throw new DependencyUnavailable('storage');
        }
        $finding = $this->findings[$workId->value->value] ?? null;
        if ($this->afterInspect !== null) {
            ($this->afterInspect)($workId);
        }

        return $finding;
    }

    public function settleLocked(ProviderRecoveryPlan $plan): void
    {
        $entry = $plan->physicalCallId->value.':'.$plan->mode->value;
        // Идемпотентность настоящего settlement: повтор не удваивает финансовую историю.
        if (! in_array($entry, $this->settlements, true)) {
            $this->settlements[] = $entry;
        }
    }

    public function revokeUnclaimedLocked(WorkId $workId): void
    {
        $this->revoked[] = $workId->value->value;
    }

    /** Late outcome той же operation: linkage (logical/physical, hashes, epoch) берётся из pinned unknown plan. */
    public static function resolved(ProviderRecoveryPlan $pinned, ProviderRecoveryMode $mode, ProviderOperationPurpose $purpose = ProviderOperationPurpose::Generation): ProviderRecoveryPlan
    {
        $receipt = in_array($mode, [ProviderRecoveryMode::ResumeFromReceipt, ProviderRecoveryMode::ResumeProviderAsset], true)
            ? new ReceiptRef(LedgerFixtures::uuid(), 'fixture', 'llm/receipts/'.$pinned->physicalCallId->value.'/response', LedgerFixtures::hash('receipt'), 1)
            : null;

        return new ProviderRecoveryPlan(
            mode: $mode,
            logicalRequestId: $pinned->logicalRequestId,
            physicalCallId: $pinned->physicalCallId,
            purpose: $purpose,
            receipt: $receipt,
            expectedInputHash: $pinned->expectedInputHash,
            recipeHash: $pinned->recipeHash,
            requestEpoch: $pinned->requestEpoch,
        );
    }

    /** Plan одного provider outcome для work с заданным immutable input hash. */
    public static function plan(ProviderRecoveryMode $mode, ContentHash $inputHash, ?ProcessUuid $physical = null, ProviderOperationPurpose $purpose = ProviderOperationPurpose::Generation): ProviderRecoveryPlan
    {
        $receipt = in_array($mode, [ProviderRecoveryMode::ResumeFromReceipt, ProviderRecoveryMode::ResumeProviderAsset], true)
            ? new ReceiptRef(LedgerFixtures::uuid(), 'fixture', 'llm/receipts/fixture/response', LedgerFixtures::hash('receipt'), 1)
            : null;

        return new ProviderRecoveryPlan(
            mode: $mode,
            logicalRequestId: LedgerFixtures::uuid(),
            physicalCallId: $physical ?? LedgerFixtures::uuid(),
            purpose: $purpose,
            receipt: $receipt,
            expectedInputHash: $inputHash,
            recipeHash: LedgerFixtures::hash('recipe'),
            requestEpoch: 1,
        );
    }
}
