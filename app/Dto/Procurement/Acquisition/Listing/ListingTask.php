<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;

/** Immutable bounded page assignment. Cursor and filter payloads belong exclusively to the adapter. */
final readonly class ListingTask
{
    public function __construct(
        public int $registryId,
        public string $registry,
        public CursorEnvelope $filter,
        public CursorEnvelope $cursor,
        public CursorEnvelope $rootCursor,
        public ContentHash $streamKey,
        public string $windowStart,
        public string $windowEnd,
        public string $closesAt,
        public ProcessUuid $rootBlockId,
        public int $pass = 1,
        public int $page = 1,
        public ?ContentHash $previousPass = null,
        public int $maxPartitions = 10000,
        public ?string $rulesHash = null,
        public int $ruleEpoch = 0,
    ) {
        foreach ([$windowStart, $windowEnd] as $day) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if ($date === false || $date->format('Y-m-d') !== $day) {
                throw new \InvalidArgumentException('Invalid coverage date.');
            }
        }
        $closing = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $closesAt, new \DateTimeZone('UTC'));
        if ($closing === false || $closing->format('Y-m-d\TH:i:s\Z') !== $closesAt) {
            throw new \InvalidArgumentException('Invalid UTC closing boundary.');
        }
        if ($registryId < 1 || $registry === '' || $windowStart > $windowEnd || $closesAt <= $windowEnd
            || $pass < 1 || $pass > 3 || $page < 1 || $page > 12 || $maxPartitions < 1 || $maxPartitions > 10000
            || ($pass === 1) !== ($previousPass === null) || $ruleEpoch < 0
            || ($rulesHash !== null && preg_match('/^[0-9a-f]{64}$/D', $rulesHash) !== 1)) {
            throw new \InvalidArgumentException('Invalid listing task.');
        }
    }

    public function next(CursorEnvelope $cursor, bool $newPass = false, ?ContentHash $digest = null, ?string $rulesHash = null): self
    {
        return new self($this->registryId, $this->registry, $this->filter, $cursor, $this->rootCursor,
            $this->streamKey, $this->windowStart, $this->windowEnd, $this->closesAt, $this->rootBlockId,
            $newPass ? $this->pass + 1 : $this->pass, $newPass ? 1 : $this->page + 1,
            $newPass ? $digest : $this->previousPass, $this->maxPartitions,
            $rulesHash ?? $this->rulesHash, $this->ruleEpoch);
    }

    public function withRuleEpoch(int $nextEpoch, string $rulesHash): self
    {
        return new self($this->registryId, $this->registry, $this->filter, $this->rootCursor, $this->rootCursor,
            $this->streamKey, $this->windowStart, $this->windowEnd, $this->closesAt, $this->rootBlockId,
            pass: 1, page: 1, previousPass: null, maxPartitions: $this->maxPartitions,
            rulesHash: $rulesHash, ruleEpoch: $nextEpoch);
    }

    public function child(CursorEnvelope $cursor, ProcessUuid $id): self
    {
        return new self($this->registryId, $this->registry, $this->filter, $cursor, $cursor,
            $this->streamKey, $this->windowStart, $this->windowEnd, $this->closesAt, $id,
            maxPartitions: $this->maxPartitions, rulesHash: $this->rulesHash, ruleEpoch: $this->ruleEpoch);
    }

    public function json(): string
    {
        return json_encode([
            'registryId' => $this->registryId, 'registry' => $this->registry,
            'filter' => self::envelope($this->filter), 'cursor' => self::envelope($this->cursor),
            'rootCursor' => self::envelope($this->rootCursor), 'streamKey' => $this->streamKey->hex,
            'windowStart' => $this->windowStart, 'windowEnd' => $this->windowEnd, 'closesAt' => $this->closesAt,
            'rootBlockId' => $this->rootBlockId->value, 'pass' => $this->pass, 'page' => $this->page,
            'previousPass' => $this->previousPass?->hex, 'maxPartitions' => $this->maxPartitions,
            'rulesHash' => $this->rulesHash, 'ruleEpoch' => $this->ruleEpoch,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $v = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($v) || ! in_array(count($v), [14, 16], true)) {
            throw new \InvalidArgumentException('Invalid listing task schema.');
        }
        foreach (['registryId', 'pass', 'page', 'maxPartitions'] as $key) {
            if (! isset($v[$key]) || ! is_int($v[$key])) {
                throw new \InvalidArgumentException('Invalid listing task integer.');
            }
        }
        foreach (['registry', 'streamKey', 'windowStart', 'windowEnd', 'closesAt', 'rootBlockId'] as $key) {
            if (! isset($v[$key]) || ! is_string($v[$key])) {
                throw new \InvalidArgumentException('Invalid listing task string.');
            }
        }
        if (! array_key_exists('previousPass', $v) || ($v['previousPass'] !== null && ! is_string($v['previousPass']))) {
            throw new \InvalidArgumentException('Invalid previous pass.');
        }

        $rulesHash = null;
        if (array_key_exists('rulesHash', $v)) {
            if ($v['rulesHash'] !== null && ! is_string($v['rulesHash'])) {
                throw new \InvalidArgumentException('Invalid rules hash.');
            }
            $rulesHash = $v['rulesHash'];
        }
        $ruleEpoch = 0;
        if (array_key_exists('ruleEpoch', $v)) {
            if (! is_int($v['ruleEpoch'])) {
                throw new \InvalidArgumentException('Invalid rule epoch.');
            }
            $ruleEpoch = $v['ruleEpoch'];
        }

        return new self($v['registryId'], $v['registry'], self::decodeEnvelope($v['filter'] ?? null),
            self::decodeEnvelope($v['cursor'] ?? null), self::decodeEnvelope($v['rootCursor'] ?? null),
            new ContentHash($v['streamKey']), $v['windowStart'], $v['windowEnd'], $v['closesAt'],
            new ProcessUuid($v['rootBlockId']), $v['pass'], $v['page'],
            $v['previousPass'] === null ? null : new ContentHash($v['previousPass']), $v['maxPartitions'],
            $rulesHash, $ruleEpoch);
    }

    /** @return array{codec: string, version: int, payload: string} */
    public static function envelope(CursorEnvelope $cursor): array
    {
        return ['codec' => $cursor->codecId, 'version' => $cursor->schemaVersion, 'payload' => $cursor->serializedPayload];
    }

    public static function decodeEnvelope(mixed $value): CursorEnvelope
    {
        if (! is_array($value) || count($value) !== 3 || ! is_string($value['codec'] ?? null)
            || ! is_int($value['version'] ?? null) || ! is_string($value['payload'] ?? null)) {
            throw new \InvalidArgumentException('Invalid envelope schema.');
        }

        return new CursorEnvelope($value['codec'], $value['version'], $value['payload']);
    }
}
