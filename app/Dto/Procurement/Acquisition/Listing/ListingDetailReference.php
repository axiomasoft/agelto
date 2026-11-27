<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;

/** Immutable handoff, stored with the accepted page; locator interpretation remains in the platform. */
final readonly class ListingDetailReference
{
    public function __construct(public ProcessUuid $intentId, public int $procurementId, public ContentHash $fingerprint,
        public ?string $sourceRevision, public ?CursorEnvelope $locator)
    {
        if ($procurementId < 1) {
            throw new \InvalidArgumentException('Invalid detail reference.');
        }
    }

    public function json(): string
    {
        return json_encode(['intent' => $this->intentId->value, 'procurement' => $this->procurementId,
            'fingerprint' => $this->fingerprint->hex, 'revision' => $this->sourceRevision,
            'locator' => $this->locator === null ? null : ListingTask::envelope($this->locator)], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $r = PayloadReader::json($json);
        $r->keys(['intent', 'procurement', 'fingerprint', 'revision', 'locator']);
        $locator = null;
        if (! $r->isNull('locator')) {
            $c = $r->object('locator');
            $c->keys(['codec', 'version', 'payload']);
            $locator = new CursorEnvelope($c->string('codec'), $c->integer('version'), $c->string('payload'));
        }

        return new self(new ProcessUuid($r->string('intent')), $r->integer('procurement'), new ContentHash($r->string('fingerprint')),
            $r->isNull('revision') ? null : $r->string('revision'), $locator);
    }
}
