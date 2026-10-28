<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use Symfony\Component\Process\Process;

/**
 * PHP's synchronous dns_get_record has no per-call timeout. Isolate it in a bounded child;
 * GNU timeout bounds the child even if the worker dies. This is never a heartbeat process.
 */
final readonly class BoundedDnsResolver
{
    /** @return non-empty-list<string> */
    public function resolve(string $host, int $seconds, ExecutionContext|OutboundExecutionContext|null $context): array
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('DNS timeout must be positive.');
        }
        $context?->tick();
        $script = <<<'PHP'
$records = dns_get_record($argv[1], DNS_A | DNS_AAAA);
if ($records === false) { exit(1); }
$addresses = [];
foreach ($records as $record) {
    if (isset($record['ip'])) { $addresses[] = $record['ip']; }
    if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
}
echo json_encode(array_values(array_unique($addresses)), JSON_THROW_ON_ERROR);
PHP;
        $process = new Process(['timeout', '--signal=TERM', '--kill-after=1s', $seconds.'s', PHP_BINARY, '-r', $script, $host]);
        $process->setTimeout($seconds + 2);
        try {
            $process->start();
            while ($process->isRunning()) {
                $context?->tick();
                $process->checkTimeout();
                usleep(50_000);
            }
            if (! $process->isSuccessful()) {
                throw new DependencyUnavailable('dns');
            }
            $addresses = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($addresses) || ! array_is_list($addresses) || $addresses === []) {
                throw new DependencyUnavailable('dns');
            }
            foreach ($addresses as $address) {
                if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                    throw new DependencyUnavailable('dns');
                }
            }

            return $addresses;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
    }
}
