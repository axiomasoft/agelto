<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Dto\Health\ReadinessCheckResult;
use App\Enums\Health\ReadinessCheckCode;
use App\Support\Config\TypedConfig;
use App\Support\Time\Monotonic;

final readonly class IsolatedReadinessProcess
{
    /**
     * @param  list<ReadinessCheckCode>  $codes
     * @param  list<ReadinessCheckCode>|null  $only
     * @param  list<string>|null  $commandOverride
     * @return list<ReadinessCheckResult>
     */
    public function checkIsolated(
        array $codes,
        ?array $only = null,
        ?float $timeoutSeconds = null,
        ?array $commandOverride = null,
    ): array {
        if ($codes === []) {
            return [];
        }

        $timeout = $timeoutSeconds ?? (float) TypedConfig::positiveInt('readiness.timeout_seconds');
        if ($timeout <= 0) {
            return self::unavailableResults($codes);
        }

        $command = $commandOverride ?? [
            PHP_BINARY,
            base_path('artisan'),
            'app:readiness',
            '--format=json',
            ...($only !== null ? ['--only='.implode(',', array_map(static fn (ReadinessCheckCode $c): string => $c->value, $only))] : []),
        ];
        if ($command === []) {
            return self::unavailableResults($codes);
        }

        $spawnCommand = is_executable('/usr/bin/setsid') ? ['/usr/bin/setsid', ...$command] : $command;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $process = @proc_open($spawnCommand, $descriptors, $pipes, base_path());
        if (! is_resource($process)) {
            return self::unavailableResults($codes);
        }

        $stdoutPipe = $pipes[1];
        stream_set_blocking($stdoutPipe, false);

        $deadlineNs = Monotonic::nanoseconds() + max(1, (int) round($timeout * 1_000_000_000));
        $pid = null;
        $stdout = '';
        $timedOut = false;
        $exitCode = null;
        /** @var array<int, int> $trackedPids */
        $trackedPids = [];

        try {
            $status = proc_get_status($process);
            $pid = $status['pid'] > 0 ? $status['pid'] : null;
            if ($pid !== null) {
                $trackedPids[$pid] = $pid;
            }

            while (true) {
                $chunk = fread($stdoutPipe, 8192);
                if (is_string($chunk) && $chunk !== '' && strlen($stdout) < 65536) {
                    $stdout .= substr($chunk, 0, 65536 - strlen($stdout));
                }

                $status = proc_get_status($process);
                if ($pid !== null) {
                    foreach (self::collectDescendants($pid) as $descendantPid) {
                        $trackedPids[$descendantPid] = $descendantPid;
                    }
                }

                if (! $status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (Monotonic::nanoseconds() >= $deadlineNs) {
                    $timedOut = true;
                    break;
                }

                usleep(10_000);
            }

            if (! $timedOut && strlen($stdout) < 65536) {
                $chunk = stream_get_contents($stdoutPipe, 65536 - strlen($stdout));
                if (is_string($chunk) && $chunk !== '') {
                    $stdout .= $chunk;
                }
            }
        } finally {
            if (is_resource($stdoutPipe)) {
                fclose($stdoutPipe);
            }
            self::terminateAndReapProcessGroup($process, $pid, $trackedPids);
        }

        if ($timedOut || Monotonic::nanoseconds() > $deadlineNs || ! in_array($exitCode, [0, 1], true)) {
            return self::unavailableResults($codes);
        }

        $decoded = json_decode(trim($stdout), true);
        if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) !== count($codes)) {
            return self::unavailableResults($codes);
        }

        $results = [];
        foreach ($codes as $index => $code) {
            $item = $decoded[$index] ?? null;
            if (! is_array($item)
                || ($item['code'] ?? null) !== $code->value
                || ! is_bool($item['passed'] ?? null)
                || ! is_string($item['message'] ?? null)) {
                return self::unavailableResults($codes);
            }

            $passed = $item['passed'] === true && $item['message'] === 'ready';
            $results[] = new ReadinessCheckResult(
                code: $code,
                passed: $passed,
                message: $passed ? 'ready' : 'unavailable',
            );
        }

        return $results;
    }

    /**
     * @param  list<ReadinessCheckCode>  $codes
     * @return list<ReadinessCheckResult>
     */
    private static function unavailableResults(array $codes): array
    {
        return array_map(
            static fn (ReadinessCheckCode $code): ReadinessCheckResult => new ReadinessCheckResult(
                code: $code,
                passed: false,
                message: 'unavailable',
            ),
            $codes,
        );
    }

    /**
     * @param  resource  $process
     * @param  array<int, int>  $trackedPids
     */
    private static function terminateAndReapProcessGroup(mixed $process, ?int $pid, array $trackedPids): void
    {
        if ($pid !== null) {
            foreach (array_values($trackedPids) as $rootPid) {
                foreach (self::collectDescendants($rootPid) as $descendantPid) {
                    $trackedPids[$descendantPid] = $descendantPid;
                }
            }

            if (function_exists('posix_kill')) {
                @posix_kill(-$pid, 15);
                foreach ($trackedPids as $targetPid) {
                    @posix_kill($targetPid, 15);
                }
                $graceUntilNs = Monotonic::nanoseconds() + 100_000_000;
                while (Monotonic::nanoseconds() < $graceUntilNs && self::anyPidPresent($trackedPids)) {
                    usleep(10_000);
                }

                foreach (array_values($trackedPids) as $rootPid) {
                    foreach (self::collectDescendants($rootPid) as $descendantPid) {
                        $trackedPids[$descendantPid] = $descendantPid;
                    }
                }

                @posix_kill(-$pid, 9);
                foreach ($trackedPids as $targetPid) {
                    @posix_kill($targetPid, 9);
                }
            }
        }

        @proc_close($process);

        if ($trackedPids !== []) {
            $reapUntilNs = Monotonic::nanoseconds() + 800_000_000;
            while (Monotonic::nanoseconds() < $reapUntilNs && self::anyPidPresent($trackedPids)) {
                if (function_exists('posix_kill')) {
                    if ($pid !== null) {
                        @posix_kill(-$pid, 9);
                    }
                    foreach ($trackedPids as $targetPid) {
                        @posix_kill($targetPid, 9);
                    }
                }
                usleep(10_000);
            }
        }
    }

    /**
     * @param  array<int, int>  $pids
     */
    private static function anyPidPresent(array $pids): bool
    {
        foreach ($pids as $pid) {
            if (self::isPidAlive($pid)) {
                return true;
            }
        }

        return false;
    }

    private static function isPidAlive(int $pid): bool
    {
        $status = @file_get_contents("/proc/{$pid}/status");
        if (is_string($status)) {
            return preg_match('/^State:\s+Z\b/m', $status) !== 1;
        }

        return is_dir("/proc/{$pid}") || (function_exists('posix_kill') && @posix_kill($pid, 0));
    }

    /**
     * @return list<int>
     */
    private static function collectDescendants(int $rootPid): array
    {
        $selfPid = getmypid() ?: 0;
        $queue = self::directChildren($rootPid);
        $visited = [];
        $order = [];

        while ($queue !== []) {
            $pid = array_shift($queue);
            if ($pid === $selfPid || isset($visited[$pid])) {
                continue;
            }
            $visited[$pid] = true;
            $order[] = $pid;
            foreach (self::directChildren($pid) as $childPid) {
                $queue[] = $childPid;
            }
        }

        return $order;
    }

    /**
     * @return list<int>
     */
    private static function directChildren(int $pid): array
    {
        $taskDir = "/proc/{$pid}/task";
        $tids = @scandir($taskDir);
        if (! is_array($tids)) {
            return [];
        }

        $children = [];
        foreach ($tids as $tid) {
            if ($tid === '.' || $tid === '..') {
                continue;
            }
            $raw = @file_get_contents("{$taskDir}/{$tid}/children");
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            foreach (preg_split('/\s+/', trim($raw)) ?: [] as $token) {
                $childPid = (int) $token;
                if ($childPid > 0) {
                    $children[] = $childPid;
                }
            }
        }

        return $children;
    }
}
