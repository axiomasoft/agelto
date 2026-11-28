<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

/** Already loaded real rows and links. Resolving this snapshot requires no I/O. */
final readonly class SubjectLockSnapshot
{
    /** @var non-empty-list<SubjectAuthoritySnapshot> */
    public array $subjects;

    /** @param array<array-key, SubjectAuthoritySnapshot> $subjects */
    public function __construct(array $subjects)
    {
        if ($subjects === [] || ! array_is_list($subjects)) {
            throw new \InvalidArgumentException('Subject snapshot must contain loaded rows.');
        }
        $keys = array_map(static fn (SubjectAuthoritySnapshot $row): string => $row->subject->key(), $subjects);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new \InvalidArgumentException('Duplicate subject authority.');
        }
        $byKey = array_combine($keys, $subjects);
        foreach ($subjects as $row) {
            if ($row->parent !== null && ! isset($byKey[$row->parent->key()])) {
                throw new \InvalidArgumentException('Subject ancestor must already be loaded.');
            }
        }
        foreach ($subjects as $row) {
            $seen = [$row->subject->key()];
            $parent = $row->parent;
            while ($parent !== null) {
                if (in_array($parent->key(), $seen, true)) {
                    throw new \InvalidArgumentException('Cyclic subject linkage.');
                }
                $seen[] = $parent->key();
                $parent = $byKey[$parent->key()]->parent;
            }
        }
        usort($subjects, static function (SubjectAuthoritySnapshot $a, SubjectAuthoritySnapshot $b): int {
            $table = $a->subject->kind->value <=> $b->subject->kind->value;
            if ($table !== 0) {
                return $table;
            }
            $left = $a->subject->id;
            $right = $b->subject->id;

            return is_int($left) && is_int($right) ? $left <=> $right : $a->subject->key() <=> $b->subject->key();
        });
        $this->subjects = $subjects;
    }
}
