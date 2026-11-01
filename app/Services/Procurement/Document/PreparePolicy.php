<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;
use Illuminate\Contracts\Config\Repository;

final readonly class PreparePolicy
{
    public int $maxPages;

    public int $chainSeconds;

    public int $reserveSeconds;

    public int $setupSeconds;

    public int $batchSeconds;

    public int $persistenceSeconds;

    public int $tmpBytes;

    public int $artifactBytes;

    public float $qualityThreshold;

    public int $tesseractTimeout;

    public string $languages;

    public string $toolsVersion;

    public string $durableDomain;

    public string $temporaryDomain;

    public string $disk;

    public string $temporaryDisk;

    public ContentHash $hash;

    public function __construct(Repository $config)
    {
        $timeout = $config->get('documents.prepare.tesseract_timeout_seconds');
        if (! is_int($timeout) || $timeout < 0 || $timeout > 150) {
            throw new \InvalidArgumentException('documents.prepare.tesseract_timeout_seconds');
        }
        $this->tesseractTimeout = $timeout;
        $this->maxPages = $this->integer($config, 'max_ocr_pages', 10000);
        $this->setupSeconds = $this->integer($config, 'setup_seconds', 200);
        $this->batchSeconds = $this->integer($config, 'batch_seconds', 200);
        $this->persistenceSeconds = $this->integer($config, 'persistence_seconds', 200);
        $this->chainSeconds = $this->integer($config, 'chain_seconds', 200);
        $this->reserveSeconds = $this->integer($config, 'reserve_seconds', 60);
        $this->tmpBytes = $this->integer($config, 'tmp_bytes', 2147483647);
        $this->artifactBytes = $this->integer($config, 'artifact_bytes', 268435456);
        $this->languages = $this->text($config, 'languages');
        $this->toolsVersion = $this->text($config, 'tools_version');
        $this->durableDomain = $this->text($config, 'durable_domain');
        $this->temporaryDomain = $this->text($config, 'temporary_domain');
        $this->disk = $this->text($config, 'disk');
        $this->temporaryDisk = $this->text($config, 'temporary_disk');
        $threshold = $config->get('documents.prepare.quality_threshold');
        if ((! is_float($threshold) && ! is_int($threshold)) || $threshold < 0 || $threshold > 1) {
            throw new \InvalidArgumentException('documents.prepare.quality_threshold');
        }
        $this->qualityThreshold = (float) $threshold;
        if ($this->setupSeconds + $this->batchSeconds + $this->persistenceSeconds > $this->chainSeconds
            || $this->reserveSeconds < 30 || $this->chainSeconds + $this->reserveSeconds > 240 || $this->tmpBytes < $this->artifactBytes
            || $this->durableDomain === $this->temporaryDomain || $config->get('documents.prepare.allow_degraded_representation') !== false) {
            throw new \InvalidArgumentException('Incompatible prepare profile or unsafe coverage default.');
        }
        $this->hash = ContentHash::of(json_encode(['v' => 1, 'tools' => $this->toolsVersion, 'languages' => $this->languages, 'tesseract_timeout' => $this->tesseractTimeout,
            'max_pages' => $this->maxPages, 'setup' => $this->setupSeconds, 'batch' => $this->batchSeconds, 'persistence' => $this->persistenceSeconds, 'chain' => $this->chainSeconds, 'quality_threshold' => $this->qualityThreshold,
            'allow_degraded' => false, 'split' => 'whole_pages_singleton_two_failures', 'target_seconds' => 150, 'max_batch' => 20], JSON_THROW_ON_ERROR));
    }

    private function text(Repository $config, string $key): string
    {
        $value = $config->get('documents.prepare.'.$key);
        if (! is_string($value) || preg_match('/^[a-zA-Z0-9_.:+ -]{1,200}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('documents.prepare.'.$key);
        }

        return $value;
    }

    private function integer(Repository $c, string $key, int $max): int
    {
        $v = $c->get('documents.prepare.'.$key);

        return is_int($v) && $v > 0 && $v <= $max ? $v : throw new \InvalidArgumentException('documents.prepare.'.$key);
    }
}
