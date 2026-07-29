<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Атомарный лиз-лок на время обработки стадии пайплайна (`locked_by`/`locked_until`) —
 * единственная реализация, переиспользуемая `Document` и `Ai\Analysis` (канон v1
 * `Old/app/Models/DocumentAnalysis/DocumentAnalysis.php::acquireLease/releaseLease`).
 * Требует колонки `locked_by` (text, nullable) и `locked_until` (timestampTz, nullable)
 * на модели-потребителе.
 */
trait HasLease
{
    /**
     * Атомарный захват записи на время обработки стадии (анти-дубль при крашах/ретраях).
     * Возвращает true, если лиз свободен или истёк и успешно взят.
     */
    public function acquireLease(string $owner, int $ttlSeconds = 600): bool
    {
        $lockedUntil = now()->addSeconds($ttlSeconds);

        $acquired = static::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull('locked_until')
                ->orWhere('locked_until', '<', now())
            )
            ->update([
                'locked_by' => $owner,
                'locked_until' => $lockedUntil,
            ]);

        if ($acquired === 1) {
            $this->forceFill(['locked_by' => $owner, 'locked_until' => $lockedUntil]);

            return true;
        }

        return false;
    }

    public function releaseLease(): void
    {
        static::query()->whereKey($this->getKey())->update(['locked_by' => null, 'locked_until' => null]);
        $this->forceFill(['locked_by' => null, 'locked_until' => null]);
    }
}
