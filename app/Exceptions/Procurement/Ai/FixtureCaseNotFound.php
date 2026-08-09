<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use App\Exceptions\Procurement\OperationFailed;
use App\Services\Procurement\Fixture\FixtureCaseResolver;

/**
 * Кейс эталона (код/analysis) не резолвится — {@see FixtureCaseResolver}.
 */
final class FixtureCaseNotFound extends OperationFailed {}
