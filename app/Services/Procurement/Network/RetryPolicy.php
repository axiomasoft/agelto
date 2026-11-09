<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Process\RetryAt;

/** Validated engineering defaults; never shorten a valid Retry-After or guess provider billing. */
final readonly class RetryPolicy
{
    public function __construct(
        public int $invalidRetryAfterSeconds = 60,
        public int $attemptsExhaustedSeconds = 30,
        public int $dependencySeconds = 30,
    ) {
        if (min($invalidRetryAfterSeconds, $attemptsExhaustedSeconds, $dependencySeconds) < 1 || $invalidRetryAfterSeconds > 86400) {
            throw new \InvalidArgumentException('Invalid outbound retry policy.');
        }
    }

    public function retryAfter(?string $header, \DateTimeImmutable $now, ?int $fallbackSeconds = null): ?RetryAt
    {
        $header = $header === null ? null : trim($header);
        if ($header !== null && preg_match('/^\d+$/D', $header)) {
            $seconds = filter_var($header, FILTER_VALIDATE_INT);
            if ($seconds === false || $seconds > 86400) {
                return null;
            }

            return new RetryAt($now->modify('+'.$seconds.' seconds'));
        }
        if ($header !== null) {
            $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $header, new \DateTimeZone('UTC'));
            if ($date !== false && $date->format('D, d M Y H:i:s \G\M\T') === $header) {
                if ($date->getTimestamp() - $now->getTimestamp() > 86400) {
                    return null;
                }
                if ($date > $now) {
                    return new RetryAt($date);
                }
            }
        }
        $defaultSeconds = $fallbackSeconds ?? $this->invalidRetryAfterSeconds;

        return new RetryAt($now->modify('+'.$defaultSeconds.' seconds'));
    }
}
