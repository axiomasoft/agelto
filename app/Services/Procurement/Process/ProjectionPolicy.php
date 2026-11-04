<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use Illuminate\Contracts\Config\Repository;

/**
 * Boot-validated policy projection из `process.projection`: bounded rebuild
 * (max events, общий deadline, размер страницы) и отсрочки сообщений outbox. Закрытая схема;
 * противоречивая конфигурация отвергается с путём ключа, а не заменяется default.
 */
final readonly class ProjectionPolicy
{
    public int $rebuildMaxEvents;

    public int $rebuildDeadlineSeconds;

    public int $rebuildPage;

    public int $quarantineRetrySeconds;

    public int $maintenanceDeferSeconds;

    public int $pruneBatch;

    public function __construct(Repository $config)
    {
        $projection = $config->get('process.projection');
        $keys = ['rebuild_max_events', 'rebuild_deadline_seconds', 'rebuild_page', 'quarantine_retry_seconds', 'maintenance_defer_seconds', 'prune_batch'];
        if (! is_array($projection)) {
            throw new \InvalidArgumentException('Invalid projection policy: process.projection');
        }
        $actual = array_keys($projection);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException('Invalid projection policy: process.projection');
        }
        $this->rebuildMaxEvents = self::positive($projection['rebuild_max_events'], 'rebuild_max_events');
        $this->rebuildDeadlineSeconds = self::positive($projection['rebuild_deadline_seconds'], 'rebuild_deadline_seconds');
        $this->rebuildPage = self::positive($projection['rebuild_page'], 'rebuild_page');
        $this->quarantineRetrySeconds = self::positive($projection['quarantine_retry_seconds'], 'quarantine_retry_seconds');
        $this->maintenanceDeferSeconds = self::positive($projection['maintenance_defer_seconds'], 'maintenance_defer_seconds');
        $this->pruneBatch = self::positive($projection['prune_batch'], 'prune_batch');
        if ($this->rebuildPage > $this->rebuildMaxEvents) {
            throw new \InvalidArgumentException('Invalid projection policy: process.projection.rebuild_page');
        }
    }

    private static function positive(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('Invalid projection policy: process.projection.'.$key);
        }

        return $value;
    }
}
