<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Services\Procurement\Network\UrlPolicy;
use Symfony\Component\Process\Process;

final class WireFixture
{
    public readonly string $root;

    /** @var array{http: int, tls: int, proxy: int, auth: int, stall_proxy: int} */
    public readonly array $ports;

    private Process $process;

    /** @var array<string, list<array{status: int, headers: array<string, string>, body_base64: string}>> */
    private array $responses = [];

    public function __construct(int $tlsPort = 0)
    {
        if (getenv('PROCESS_CORE_TEST') !== '1' || ! app()->environment('testing')) {
            throw new \RuntimeException('Real wire tests require isolated core rig.');
        }
        $this->root = sys_get_temp_dir().'/wire-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $certificate = new Process(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=fixture.test', '-addext', 'subjectAltName=DNS:fixture.test',
            '-keyout', $this->root.'/key.pem', '-out', $this->root.'/cert.pem']);
        $certificate->mustRun();
        $this->process = new Process(['python3', base_path('tests/Support/ProcessCore/Http/server.py'), $this->root, (string) $tlsPort]);
        $this->process->setTimeout(null)->start();
        $until = hrtime(true) + 5_000_000_000;
        while (! str_contains($this->process->getOutput(), "\n")) {
            if (! $this->process->isRunning() || hrtime(true) > $until) {
                $this->stop();
                throw new \RuntimeException('Local wire fixture failed to start: '.$this->process->getErrorOutput());
            }
            usleep(10_000);
        }
        $this->ports = json_decode(trim($this->process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    public function url(string $path, bool $tls = false, string $host = 'fixture.test'): SafeUrl
    {
        return new SafeUrl(($tls ? 'https' : 'http').'://'.$host.':'.$this->ports[$tls ? 'tls' : 'http'].$path);
    }

    public function policy(): UrlPolicy
    {
        return new UrlPolicy([
            CanonicalOrigin::fromUrl($this->url('/')->reveal()),
            CanonicalOrigin::fromUrl($this->url('/', true)->reveal()),
            CanonicalOrigin::fromUrl($this->url('/', false, 'second.test')->reveal()),
        ], ['fixture.test' => ['127.0.0.1'], 'second.test' => ['127.0.0.1']]);
    }

    /** @param array<string, string> $headers */
    public function respond(string $path, string $body, int $status = 200, array $headers = [], string $method = 'GET', bool $replace = false): void
    {
        $key = $method.' '.$path;
        if ($replace) {
            $this->responses[$key] = [];
        }
        $this->responses[$key][] = ['status' => $status, 'headers' => (object) $headers, 'body_base64' => base64_encode($body)];
        file_put_contents($this->root.'/responses.tmp', json_encode($this->responses, JSON_THROW_ON_ERROR));
        rename($this->root.'/responses.tmp', $this->root.'/responses.json');
    }

    /** @return list<array<string, mixed>> */
    public function receipts(): array
    {
        if (! is_file($this->root.'/requests.jsonl')) {
            return [];
        }

        return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->root.'/requests.jsonl', FILE_IGNORE_NEW_LINES));
    }

    public function errors(): string
    {
        return $this->process->getErrorOutput();
    }

    public function stop(): void
    {
        if (isset($this->process)) {
            $this->process->stop(0.5);
        }
        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }
}
