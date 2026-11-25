<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;

/** Prices are integer kopecks, endpoints inclusive. Only this adapter decodes the payload. */
final readonly class EisListingCursor
{
    public const int MAX_PRICE_CENTS = 100_000_000_000_000;

    private const int LEGACY_OPEN_PRICE_FLOOR = 100_000_000_000;

    public function __construct(
        public string $day, public string $dateField, public int $lowCents, public ?int $highCents,
        public ContentHash $filterHash, public int $firstRow = 1, public int $depth = 0, public int $blockSize = 500,
    ) {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date === false || $date->format('Y-m-d') !== $day || ! in_array($dateField, ['publish', 'update'], true)
            || $lowCents < 0 || $lowCents > self::MAX_PRICE_CENTS
            || ($highCents !== null && ($highCents <= 0 || $highCents < $lowCents || $highCents > self::MAX_PRICE_CENTS))
            || $firstRow < 1 || $firstRow > 12000 || $depth < 0 || $depth > 20
            || $blockSize < 1 || $blockSize > 1000 || ($firstRow - 1) % $blockSize !== 0) {
            throw new \InvalidArgumentException('Invalid EIS cursor.');
        }
    }

    public function envelope(): CursorEnvelope
    {
        return new CursorEnvelope('eis.listing', 2, json_encode([
            'day' => $this->day, 'dateField' => $this->dateField, 'lowCents' => $this->lowCents,
            'highCents' => $this->highCents, 'filterHash' => $this->filterHash->hex,
            'firstRow' => $this->firstRow, 'depth' => $this->depth, 'blockSize' => $this->blockSize,
        ], JSON_THROW_ON_ERROR));
    }

    public static function decode(CursorEnvelope $envelope): self
    {
        if ($envelope->codecId !== 'eis.listing' || ! in_array($envelope->schemaVersion, [1, 2], true)) {
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
        foreach (['lowCents', 'firstRow', 'depth', 'blockSize'] as $key) {
            if (! is_int($data[$key])) {
                throw new \InvalidArgumentException('Invalid EIS cursor integer.');
            }
        }

        $high = $data['highCents'];
        if (! is_int($high) && ($high !== null || $envelope->schemaVersion === 1)) {
            throw new \InvalidArgumentException('Invalid EIS cursor upper bound.');
        }

        // Version 1 omitted the upper filter for every bound at or above one billion rubles.
        if ($envelope->schemaVersion === 1 && $high >= self::LEGACY_OPEN_PRICE_FLOOR
            && $high <= self::MAX_PRICE_CENTS && $high >= $data['lowCents']) {
            $high = null;
        }

        return new self(day: $data['day'], dateField: $data['dateField'], lowCents: $data['lowCents'], highCents: $high,
            filterHash: new ContentHash($data['filterHash']), firstRow: $data['firstRow'], depth: $data['depth'], blockSize: $data['blockSize']);
    }

    /** Keep the existing stream keys for the default open scope and correctly bounded legacy scopes. */
    public function streamUpperBound(): int|string
    {
        if ($this->highCents === null) {
            return self::LEGACY_OPEN_PRICE_FLOOR;
        }

        return $this->highCents >= self::LEGACY_OPEN_PRICE_FLOOR ? (string) $this->highCents : $this->highCents;
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
