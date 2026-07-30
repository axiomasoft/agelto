<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Services\Procurement\Analyzers\Estimate\EvalSplitPlanner;

/**
 * Пул `gold_source=deterministic` пуст — {@see EvalSplitPlanner}
 * не может пересобрать `estimates/eval-splits.json`.
 */
final class SplitPoolEmpty extends OperationFailed {}
