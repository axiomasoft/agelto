<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\AdapterValidation;

/** @template-covariant T */
final readonly class AdapterValidatedInput
{
    /** @param T $value */
    public function __construct(public mixed $value, public ?AdapterValidationReceipt $receipt) {}
}
