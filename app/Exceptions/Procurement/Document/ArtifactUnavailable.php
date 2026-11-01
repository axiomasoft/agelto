<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Document;

/** Dependency wait: caller retries after verification/cleanup; never an item failure. */
final class ArtifactUnavailable extends \RuntimeException {}
