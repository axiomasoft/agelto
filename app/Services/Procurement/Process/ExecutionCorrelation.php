<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ExecutionLogContext;
use App\Repositories\Procurement\Process\RunReadRepository;
use Illuminate\Support\Facades\Context;

/**
 * Correlation одного attempt (observability#s02): ID берутся из БД на claim, а Laravel Context
 * существует только на время работы и восстанавливается в finally, поэтому следующий job не
 * наследует run/work/attempt/platform/URL/actor предыдущего. Недоступность БД не ломает delivery:
 * контекст теряет только run/platform.
 */
final readonly class ExecutionCorrelation
{
    /** Ключи, которые attempt обязан не оставлять следующему job в worker. */
    private const array SCOPED_KEYS = ['run_id', 'work_id', 'attempt_id', 'stage', 'platform_code', 'trace_id', 'url', 'route', 'actor_id'];

    public function __construct(private RunReadRepository $runs) {}

    public function describe(ExecutionLease $lease): ExecutionLogContext
    {
        try {
            $correlation = $this->runs->correlationFor($lease->workId);
        } catch (\Throwable) {
            $correlation = null;
        }
        $cohort = $correlation['cohort_key'] ?? null;
        $platform = match (true) {
            $cohort === null => null,
            $cohort === 'manual' => 'manual',
            str_starts_with($cohort, 'platform:') => substr($cohort, strlen('platform:')),
            default => null,
        };

        return ExecutionLogContext::forLease($lease, $correlation['run_id'] ?? null, $platform !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $platform) === 1 ? $platform : null);
    }

    /**
     * @template T
     *
     * @param  \Closure(ExecutionLogContext): T  $work
     * @return T
     */
    public function scope(ExecutionLease $lease, \Closure $work): mixed
    {
        $log = $this->describe($lease);
        $before = [];
        foreach (self::SCOPED_KEYS as $key) {
            if (Context::has($key)) {
                $before[$key] = Context::get($key);
            }
        }
        // Родительский HTTP/command trace остаётся ссылкой, но не trace_id нового attempt.
        $parent = $before['trace_id'] ?? null;
        foreach (self::SCOPED_KEYS as $key) {
            Context::forget($key);
        }
        Context::add($log->toContext());
        if (is_string($parent)) {
            Context::add('parent_trace_id', $parent);
        }
        try {
            return $work($log);
        } finally {
            foreach ([...self::SCOPED_KEYS, 'parent_trace_id'] as $key) {
                Context::forget($key);
            }
            foreach ($before as $key => $value) {
                Context::add($key, $value);
            }
        }
    }
}
