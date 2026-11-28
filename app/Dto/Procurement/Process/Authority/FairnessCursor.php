<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Enums\Procurement\Process\Core\StageCode;

/**
 * Durable fairness cursor v1 parent lane: последний cohort и последняя stage
 * внутри каждого cohort. Меняется только committed admission под lock parent lane; payload
 * ограничен числом зарегистрированных cohorts.
 */
final readonly class FairnessCursor
{
    public const int MAX_COHORTS = 256;

    /** @param array<string, StageCode> $lastStageByCohort */
    public function __construct(public ?string $lastCohort, public array $lastStageByCohort)
    {
        if ($lastCohort !== null) {
            CohortKey::assert($lastCohort);
        }
        if (count($lastStageByCohort) > self::MAX_COHORTS) {
            throw new \InvalidArgumentException('Fairness cursor exceeds its cohort bound.');
        }
        foreach (array_keys($lastStageByCohort) as $cohort) {
            CohortKey::assert((string) $cohort);
        }
        if ($lastCohort !== null && ! isset($lastStageByCohort[$lastCohort])) {
            throw new \InvalidArgumentException('Last cohort must have its last stage.');
        }
    }

    public static function empty(): self
    {
        return new self(null, []);
    }

    public function advance(string $cohort, StageCode $stage): self
    {
        $stages = $this->lastStageByCohort;
        $stages[$cohort] = $stage;
        ksort($stages, SORT_STRING);

        return new self($cohort, $stages);
    }

    public function lastStage(string $cohort): ?StageCode
    {
        return $this->lastStageByCohort[$cohort] ?? null;
    }

    /**
     * Round-robin порядок: элементы строго после last, затем с начала (включая last последним).
     *
     * @template T of string
     *
     * @param  list<T>  $sorted
     * @return list<T>
     */
    public static function after(array $sorted, ?string $last): array
    {
        if ($last === null) {
            return $sorted;
        }
        $later = array_values(array_filter($sorted, static fn (string $item): bool => strcmp($item, $last) > 0));
        $earlier = array_values(array_filter($sorted, static fn (string $item): bool => strcmp($item, $last) <= 0));

        return [...$later, ...$earlier];
    }
}
