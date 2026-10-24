<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Unlocked repository pre-read; never a grant to mutate or send. Revalidate under locks. */
final readonly class AuthoritySnapshot
{
    /** @var non-empty-list<ControlSnapshot> */
    public array $controls;

    /** @var non-empty-list<CapacityAuthority> */
    public array $capacity;

    /**
     * @param  array<array-key, ControlSnapshot>  $controls  Full ancestor closure, not just overrides.
     * @param  array<array-key, CapacityAuthority>  $capacity  All lane and storage authorities.
     * @param  array<array-key, ProviderOperationRef>  $providerOperations
     */
    public function __construct(
        public WorkId $workId,
        public WorkInput $input,
        public int $inputRevision,
        public ContentHash $inputHash,
        array $controls,
        public HandlerAuthoritySnapshot $handler,
        array $capacity,
        public SubjectLockSnapshot $subjects,
        public ?AdmissionGroupSnapshot $admissionGroup,
        public array $providerOperations,
    ) {
        ProcessValue::positive($inputRevision);
        if ($controls === [] || ! array_is_list($controls) || $capacity === [] || ! array_is_list($capacity) || ! array_is_list($providerOperations)) {
            throw new \InvalidArgumentException('Authority collections must be explicit typed lists.');
        }
        $controlIds = array_map(static fn (ControlSnapshot $scope): string => $scope->id->value, $controls);
        $scopeKeys = array_map(static fn (ControlSnapshot $scope): string => $scope->scopeKey, $controls);
        $capacityKeys = array_map(static fn (CapacityAuthority $authority): string => $authority->key(), $capacity);
        $providerIds = array_map(static fn (ProviderOperationRef $operation): string => $operation->physicalCallId->value, $providerOperations);
        foreach ([$controlIds, $scopeKeys, $capacityKeys, $providerIds] as $keys) {
            if (count(array_unique($keys)) !== count($keys)) {
                throw new \InvalidArgumentException('Duplicate authority identity.');
            }
        }
        if ($handler->stage !== $input->stage()) {
            throw new \InvalidArgumentException('Handler authority stage mismatch.');
        }
        if ($admissionGroup !== null) {
            foreach ($admissionGroup->laneIds as $lane) {
                if (! in_array('lane:'.$lane->value, $capacityKeys, true)) {
                    throw new \InvalidArgumentException('Active membership is missing a capacity authority.');
                }
            }
        }
        usort($controls, static fn (ControlSnapshot $a, ControlSnapshot $b): int => $a->scopeKey <=> $b->scopeKey);
        usort($capacity, static fn (CapacityAuthority $a, CapacityAuthority $b): int => $a->key() <=> $b->key());
        $this->controls = $controls;
        $this->capacity = $capacity;
    }
}
