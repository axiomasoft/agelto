<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Единая формула backoff/попыток error-блока (`attempts`/`last_error`/`last_error_at`/
 * `next_retry_at`/`failed_permanently`) — единственная реализация, переиспользуемая
 * `Document` и `Ai\Analysis`, чтобы не плодить разнобой формул по job'ам (v1-урок,
 * research/A §Слабые). Экспоненциальный backoff с потолком (P4.1 Code Guidance):
 * `min(2 ** attempts, config('ai.retry.max_backoff_minutes'))` минут; окончательный провал —
 * `attempts >= config('ai.retry.max_attempts')`. Смена доменного статуса (`failed`/…) —
 * ответственность вызывающего job'а, эта черта только про счётчик попыток и retry-окно.
 */
trait HasErrorBlock
{
    public function recordFailure(string $message): void
    {
        if ($this->hasLeaseToken() && ! $this->ownsLease()) {
            return;
        }

        $this->refresh();
        $attempts = $this->attempts + 1;
        $maxAttempts = $this->positiveIntegerConfig('ai.retry.max_attempts', default: 5);
        $isPermanent = $attempts >= $maxAttempts;
        $backoffMinutes = min(2 ** $attempts, $this->positiveIntegerConfig('ai.retry.max_backoff_minutes', default: 240));

        $this->forceFill([
            'attempts' => $attempts,
            'last_error' => $message,
            'last_error_at' => now(),
            'next_retry_at' => $isPermanent ? null : now()->addMinutes($backoffMinutes),
            'failed_permanently' => $isPermanent,
        ])->save();
    }

    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
