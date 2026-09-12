<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A database-clock lease with a unique token for every execution.
 *
 * Conditional release and ownership checks fence a resumed stale worker. Taking over an
 * expired lease first records exactly one failed execution and leaves retry scheduling to
 * the durable error block.
 */
trait HasLease
{
    private ?string $leaseToken = null;

    public function acquireLease(string $owner, int $ttlSeconds = 600): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Lease TTL must be a positive integer.');
        }

        $databaseNow = $this->leaseDatabaseNow();

        if ($this->recordExpiredExecution(databaseNow: $databaseNow)) {
            $this->refresh();

            return false;
        }

        $token = "{$owner}:".Str::uuid()->toString();
        $lockedUntil = $databaseNow->addSeconds($ttlSeconds);

        $acquired = static::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull('locked_until')
                ->orWhere('locked_until', '<', $databaseNow)
            )
            ->update([
                'locked_by' => $token,
                'locked_until' => $lockedUntil,
            ]);

        if ($acquired !== 1) {
            return false;
        }

        $this->leaseToken = $token;
        $this->refresh();

        return true;
    }

    public function releaseLease(): void
    {
        if ($this->leaseToken === null) {
            return;
        }

        $released = static::query()
            ->whereKey($this->getKey())
            ->where('locked_by', $this->leaseToken)
            ->update(['locked_by' => null, 'locked_until' => null]);

        $this->leaseToken = null;

        if ($released === 1) {
            $this->forceFill(['locked_by' => null, 'locked_until' => null]);
        }
    }

    public function hasLeaseToken(): bool
    {
        return $this->leaseToken !== null;
    }

    public function leaseToken(): ?string
    {
        return $this->leaseToken;
    }

    public function ownsLease(): bool
    {
        if ($this->leaseToken === null) {
            return false;
        }

        return static::query()
            ->whereKey($this->getKey())
            ->where('locked_by', $this->leaseToken)
            ->where('locked_until', '>=', $this->leaseDatabaseNow())
            ->exists();
    }

    private function recordExpiredExecution(CarbonImmutable $databaseNow): bool
    {
        return DB::transaction(function () use ($databaseNow): bool {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->locked_by === null || $locked->locked_until === null
                || $locked->locked_until->greaterThanOrEqualTo($databaseNow)) {
                return false;
            }

            $attempts = $locked->attempts + 1;
            $maxAttempts = $this->leasePositiveIntegerConfig(key: 'ai.retry.max_attempts', default: 5);
            $maxBackoff = $this->leasePositiveIntegerConfig(key: 'ai.retry.max_backoff_minutes', default: 240);
            $failedPermanently = $attempts >= $maxAttempts;

            /** @var array<string, mixed> $attributes */
            $attributes = [
                'attempts' => $attempts,
                'last_error' => 'Previous pipeline execution exceeded its lease.',
                'last_error_at' => $databaseNow,
                'next_retry_at' => $failedPermanently
                    ? null
                    : $databaseNow->addMinutes(min(2 ** $attempts, $maxBackoff)),
                'failed_permanently' => $failedPermanently,
                'locked_by' => null,
                'locked_until' => null,
            ];

            if ($failedPermanently && method_exists($locked, 'leaseExhaustedAttributes')) {
                $extraAttributes = $locked->leaseExhaustedAttributes($databaseNow);

                if (! is_array($extraAttributes)) {
                    throw new \LogicException('leaseExhaustedAttributes() must return an attribute array.');
                }

                foreach ($extraAttributes as $key => $value) {
                    if (! is_string($key)) {
                        throw new \LogicException('leaseExhaustedAttributes() must return string-keyed attributes.');
                    }

                    $attributes[$key] = $value;
                }
            }

            $locked->forceFill($attributes)->save();

            if ($failedPermanently && method_exists($locked, 'afterLeaseExhausted')) {
                $locked->afterLeaseExhausted($databaseNow);
            }

            return true;
        });
    }

    private function leaseDatabaseNow(): CarbonImmutable
    {
        $value = DB::scalar('SELECT CURRENT_TIMESTAMP');

        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw new RuntimeException('Database clock did not return a timestamp.');
        }

        return CarbonImmutable::parse($value);
    }

    private function leasePositiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
