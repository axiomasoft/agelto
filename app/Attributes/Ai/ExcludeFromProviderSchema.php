<?php

declare(strict_types=1);

namespace App\Attributes\Ai;

use Attribute;

/** Excludes a backend-only enum case from provider-facing structured-output schemas. */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class ExcludeFromProviderSchema {}
