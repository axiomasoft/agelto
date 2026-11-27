<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;

/**
 * Purpose/outcome resolver provider operation: единственный способ превратить
 * durable факты платной operation в recovery plan. Общий recovery не применяет receipt→resume
 * напрямую. Mapping на provider ledger принадлежит реализации resolver, которую composition
 * root подключает до admission stage с provider operations.
 *
 * Разбор plan.mode: ResumeFromReceipt/ResumeProviderAsset — verified receipt (pinned в plan);
 * KnownRetryEligible — доказанный not-sent либо known-nonbillable (receipt может отсутствовать);
 * BlockedUnknown — факты не доказывают отсутствие оплаты: reserve сохраняется, send запрещён.
 */
interface ProviderRecoveryResolver
{
    /**
     * Unlocked pre-read: object/hash verification допустимы здесь, не под DB locks. null — у work нет
     * нерешённой provider operation. Недоступное хранилище — DependencyUnavailable('storage'): это
     * ожидание зависимости, а не разрешение нового send.
     *
     * @param  ProviderRecoveryPlan|null  $pinned  plan, уже pinned в checkpoint envelope (blocked scan)
     */
    public function inspect(WorkId $workId, ?ProviderRecoveryPlan $pinned): ?ProviderRecoveryPlan;

    /**
     * Под полным prefix и локами work/provider rows, только DB: идемпотентный settlement original
     * reservation по plan (not-sent и known-nonbillable → 0, receipt → фактический usage либо
     * conservative upper bound). Вызывается и для cancelled/superseded work: финансовая история
     * сохраняется, бизнес-публикации и возобновления нет. Для BlockedUnknown не вызывается.
     * Resolver повторно сверяет durable linkage, purpose, input/recipe, request epoch и receipt hash
     * под financial/request locks; устаревший inspect не разрешает settlement другой operation.
     */
    public function settleLocked(ProviderRecoveryPlan $plan): void;

    /**
     * Под полным prefix: инвалидирует ещё не claimed send authorization отменяемой work. Уже
     * claimed physical send не отзывается — его receipt и settlement разрешены без публикации.
     */
    public function revokeUnclaimedLocked(WorkId $workId): void;
}
