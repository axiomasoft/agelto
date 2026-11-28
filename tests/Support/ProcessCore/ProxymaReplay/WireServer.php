<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\ProxymaReplay;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

final class WireServer
{
    public readonly string $root;

    public readonly string $certificates;

    public readonly array $ports;

    private Process $process;

    public function __construct()
    {
        if (getenv('PROCESS_CORE_TEST') !== '1' || ! app()->environment('testing')
            || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test') {
            throw new RuntimeException('Wire replay requires the isolated agelto_test rig.');
        }
        $this->root = sys_get_temp_dir().'/proxyma-wire-'.bin2hex(random_bytes(8));
        if (! mkdir($this->root, 0700)) {
            throw new RuntimeException('Unable to create owned fixture directory.');
        }
        $this->certificates = base_path('tests/Fixtures/ProcessCore/ProxymaReplay/Http/certificates');
        $this->process = new Process(['python3', base_path('tests/Fixtures/ProcessCore/ProxymaReplay/Http/wire.py'), $this->root, $this->certificates]);
        $this->process->setTimeout(null);
        $this->process->start();
        if (! $this->process->waitUntil(fn ($type, $output) => str_contains($output, 'ready'))) {
            throw new RuntimeException('Wire fixture did not start: '.$this->process->getErrorOutput());
        }
        $this->ports = json_decode(file_get_contents($this->root.'/ports.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function url(string $path = '/ok', string $listener = 'http'): string
    {
        return ($listener === 'http' ? 'http' : 'https').'://origin.test:'.$this->ports[$listener].$path;
    }

    public function events(?string $kind = null): array
    {
        $lines = is_file($this->root.'/events.jsonl') ? file($this->root.'/events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $events = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);

        return array_values(array_filter($events, static fn ($event) => $kind === null || $event['kind'] === $kind));
    }

    public function await(string $kind, int $count = 1): array
    {
        $until = hrtime(true) + 5_000_000_000;
        do {
            $events = $this->events($kind);
            if (count($events) >= $count) {
                return $events;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        throw new RuntimeException('Missing actual socket evidence: '.$kind);
    }

    public function assertNoConnections(): void
    {
        // Отдельное окно наблюдения подтверждает отсутствие bytes на всех listeners.
        usleep(30000);
        if ($this->events() !== []) {
            throw new RuntimeException('Unexpected socket evidence: '.json_encode($this->events()));
        }
    }

    public function release(): void
    {
        file_put_contents($this->root.'/release', 'release');
    }

    public function stop(): void
    {
        $this->process->stop(0.1);
        $events = $this->events();
        foreach ($events as &$event) {
            if (isset($event['headers'])) {
                $event['headers'] = array_intersect_key($event['headers'], array_flip(['host', 'content-length', 'x-fixture']));
            }
        }
        unset($event);
        file_put_contents(sys_get_temp_dir().'/agelto-proxyma-replay-wire.jsonl', json_encode([
            'fixture' => basename($this->root), 'scope' => 'isolated-loopback', 'events' => $events,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        foreach (glob($this->root.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }
}
