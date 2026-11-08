<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;

/** Prices are integer kopecks, endpoints inclusive. Only this adapter decodes the payload. */
final readonly class EisListingCursor
{
    public const int UNBOUNDED_HIGH_CENTS = 100_000_000_000;

    public function __construct(
        public string $day, public string $dateField, public int $lowCents, public int $highCents,
        public ContentHash $filterHash, public int $firstRow = 1, public int $depth = 0, public int $blockSize = 500,
    ) {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date === false || $date->format('Y-m-d') !== $day || ! in_array($dateField, ['publish', 'update'], true)
            || $lowCents < 0 || $highCents <= 0 || $highCents < $lowCents || $highCents > 100_000_000_000_000
            || $firstRow < 1 || $firstRow > 12000 || $depth < 0 || $depth > 20
            || $blockSize < 1 || $blockSize > 1000 || ($firstRow - 1) % $blockSize !== 0) {
            throw new \InvalidArgumentException('Invalid EIS cursor.');
        }
    }

    public function envelope(): CursorEnvelope
    {
        return new CursorEnvelope('eis.listing', 1, json_encode([
            'day' => $this->day, 'dateField' => $this->dateField, 'lowCents' => $this->lowCents,
            'highCents' => $this->highCents, 'filterHash' => $this->filterHash->hex,
            'firstRow' => $this->firstRow, 'depth' => $this->depth, 'blockSize' => $this->blockSize,
        ], JSON_THROW_ON_ERROR));
    }

    public static function decode(CursorEnvelope $envelope): self
    {
        if ($envelope->codecId !== 'eis.listing' || $envelope->schemaVersion !== 1) {
            throw new \InvalidArgumentException('Unsupported EIS cursor codec.');
        }
        $data = json_decode($envelope->serializedPayload, true, 16, JSON_THROW_ON_ERROR);
        $keys = ['day', 'dateField', 'lowCents', 'highCents', 'filterHash', 'firstRow', 'depth', 'blockSize'];
        if (! is_array($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new \InvalidArgumentException('Invalid EIS cursor schema.');
        }
        foreach (['day', 'dateField', 'filterHash'] as $key) {
            if (! is_string($data[$key])) {
                throw new \InvalidArgumentException('Invalid EIS cursor string.');
            }
        }
        foreach (['lowCents', 'highCents', 'firstRow', 'depth', 'blockSize'] as $key) {
            if (! is_int($data[$key])) {
                throw new \InvalidArgumentException('Invalid EIS cursor integer.');
            }
        }

        return new self($data['day'], $data['dateField'], $data['lowCents'], $data['highCents'],
            new ContentHash($data['filterHash']), $data['firstRow'], $data['depth'], $data['blockSize']);
    }

    public function next(int $blockSize): self
    {
        if ($blockSize !== $this->blockSize) {
            throw new \InvalidArgumentException('EIS cursor block policy changed.');
        }

        return new self($this->day, $this->dateField, $this->lowCents, $this->highCents, $this->filterHash, $this->firstRow + $blockSize, $this->depth, $this->blockSize);
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
