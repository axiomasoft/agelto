<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use Illuminate\Contracts\Config\Repository;

/** Validated once when assembled, independent of crawler execution switches. */
final readonly class DownloadPolicy
{
    public int $maxBytes;

    public int $revalidationSeconds;

    public string $durableDomain;

    public string $temporaryDomain;

    public string $disk;

    public string $temporaryDisk;

    public function __construct(Repository $config)
    {
        $this->maxBytes = $this->integer($config, 'max_bytes');
        $this->revalidationSeconds = $this->integer($config, 'revalidation_seconds');
        $this->durableDomain = $this->text($config, 'durable_domain');
        $this->temporaryDomain = $this->text($config, 'temporary_domain');
        $this->disk = $this->text($config, 'disk');
        $this->temporaryDisk = $this->text($config, 'temporary_disk');
        if ($this->durableDomain === $this->temporaryDomain) {
            throw new \InvalidArgumentException('Download needs distinct durable and temporary capacity domains.');
        }
    }

    private function integer(Repository $config, string $key): int
    {
        $value = $config->get('documents.download.'.$key);
        if (! is_int($value) || $value < 1 || $value > 2147483647) {
            throw new \InvalidArgumentException('documents.download.'.$key.': positive bounded integer required.');
        }

        return $value;
    }

    private function text(Repository $config, string $key): string
    {
        $value = $config->get('documents.download.'.$key);
        if (! is_string($value) || preg_match('/^[a-zA-Z0-9_.:-]{1,96}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('documents.download.'.$key.': invalid storage identity.');
        }

        return $value;
    }
}
