<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\DaemonTick;
use App\Enums\Procurement\Process\DaemonKind;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;

/**
 * Reconcile daemon skeleton (bp-daemons): tick 5 s ±20%, bounded категории recovery одна за другой.
 * Скелет P1.10 даёт цикл, расписание, изоляцию сбоя категории и heartbeat/readiness; сами категории
 * (expired running/admitted recovery, blocked scans, invariant scan, handler fault sweep,
 * blocked-provider recovery) регистрирует P1.11 через register(). Без зарегистрированных
 * категорий daemon честно ничего не восстанавливает — это не заявляется как готовый recovery.
 * Сбой одной категории не останавливает остальные: tick возвращает bounded код ошибки (degraded),
 * неожиданный Throwable категории логируется sanitized и не маскируется под успех.
 */
final class ReconcileDaemon implements ProcessDaemon
{
    /** @var list<ReconcileCategory> */
    private array $categories = [];

    public function __construct(private readonly DaemonPolicy $policy) {}

    public function register(ReconcileCategory $category): void
    {
        foreach ($this->categories as $existing) {
            if ($existing->name() === $category->name()) {
                throw new \LogicException('Reconcile category is already registered: '.$category->name());
            }
        }
        $this->categories[] = $category;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_map(static fn (ReconcileCategory $category): string => $category->name(), $this->categories);
    }

    public function kind(): DaemonKind
    {
        return DaemonKind::Reconcile;
    }

    public function tick(): DaemonTick
    {
        $processed = 0;
        $error = null;
        foreach ($this->categories as $category) {
            try {
                $processed += $category->reconcile();
            } catch (\Throwable $failure) {
                ProcessLog::daemonError('reconcile', 'reconcile_category_failed', $failure, ['category' => $category->name()]);
                $error = 'reconcile_category_failed';
            }
        }

        return new DaemonTick($processed, $error);
    }

    public function delayMs(DaemonTick $last): int
    {
        return DaemonPolicy::jittered($this->policy->reconcileTickSeconds, $this->policy->reconcileJitterPercent);
    }
}
