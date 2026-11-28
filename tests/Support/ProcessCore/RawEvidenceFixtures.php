<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Models\Procurement\Crawler\RawArtifact;
use Illuminate\Support\Facades\Storage;

/** Keep real acquisition evidence writes local and remove only this test's catalog rows. */
final readonly class RawEvidenceFixtures
{
    private array $before;

    public function __construct()
    {
        $this->before = RawArtifact::query()->pluck('id')->all();
        Storage::fake('s3');
    }

    public function clean(): void
    {
        RawArtifact::query()->whereNotIn('id', $this->before)->delete();
    }
}
