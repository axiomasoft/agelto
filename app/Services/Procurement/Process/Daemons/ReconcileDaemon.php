<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Daemons;

use App\Dto\Procurement\Process\Governance\DaemonTick;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;
use Closure;

/**
 * Reconcile daemon: tick 5 s ±20%, bounded категории recovery одна за другой. Категории
 * (expired running/admitted recovery, blocked scans, invariant scan, handler fault sweep,
 * blocked-provider recovery) composition root подключает через register(). Без зарегистрированных
 * категорий tick не меняет состояние work.
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

    public function tick(?Closure $heartbeat = null): DaemonTick
    {
        $processed = 0;
        $error = null;
        foreach ($this->categories as $category) {
            $heartbeat?->__invoke();
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
