<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Services\Procurement\Fixture\FixtureManifestReader;

/**
 * `estimates/manifest.json` не найден, не парсится как JSON или не имеет ожидаемой формы
 * (`{"version": 1, "fixtures": [...]}`) — {@see FixtureManifestReader}.
 */
final class ManifestUnreadable extends OperationFailed {}
