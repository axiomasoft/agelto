<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CollectionGuard;

/** Optional source facts; extensionFields declares the non-null metadata observed by a partial listing. */
final readonly class ListingFacts
{
    private const array FIELDS = ['procedureType', 'procedureTypeRaw', 'biddingStartsAt', 'biddingEndsAt', 'customerName'];

    /** @var list<string> */
    public array $fields;

    /**
     * @param  list<string>  $extensionFields
     * @param  list<string>|null  $fields  Null infers non-null facts; an explicit list can also declare confirmed empty values.
     */
    public function __construct(
        public ?string $procedureType = null,
        public ?string $procedureTypeRaw = null,
        public ?\DateTimeImmutable $biddingStartsAt = null,
        public ?\DateTimeImmutable $biddingEndsAt = null,
        public ?string $customerName = null,
        public ?object $extension = null,
        public array $extensionFields = [],
        ?array $fields = null,
    ) {
        $observed = $fields ?? array_keys(array_filter([
            'procedureType' => $procedureType, 'procedureTypeRaw' => $procedureTypeRaw,
            'biddingStartsAt' => $biddingStartsAt, 'biddingEndsAt' => $biddingEndsAt, 'customerName' => $customerName,
        ], static fn (mixed $value): bool => $value !== null));
        CollectionGuard::check($observed, 'string', count(self::FIELDS));
        if (array_diff($observed, self::FIELDS) !== [] || count(array_unique($observed)) !== count($observed)) {
            throw new \InvalidArgumentException('Invalid observed listing facts.');
        }
        $this->fields = $observed;
        CollectionGuard::check($extensionFields, 'string', 128);
        if (count(array_unique($extensionFields)) !== count($extensionFields)
            || ($extension === null) !== ($extensionFields === [])) {
            throw new \InvalidArgumentException('Invalid listing extension field set.');
        }
        if ($extension !== null) {
            if (! (new \ReflectionClass($extension))->isReadOnly()) {
                throw new \InvalidArgumentException('Immutable listing extension required.');
            }
            $values = get_object_vars($extension);
            foreach ($extensionFields as $field) {
                if (! array_key_exists($field, $values) || $values[$field] === null) {
                    throw new \InvalidArgumentException('Unknown listing extension field.');
                }
            }
        }
    }

    public function observes(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    public function mergeExtension(?object $current): ?object
    {
        $patch = $this->extension;
        if ($patch === null) {
            return $current;
        }
        if ($current !== null && $current::class !== $patch::class) {
            throw new \InvalidArgumentException('Listing extension type differs from the current head.');
        }
        $values = get_object_vars($current ?? $patch);
        foreach ($this->extensionFields as $field) {
            $values[$field] = $patch->{$field};
        }
        $type = $patch::class;

        return new $type(...$values);
    }
}
