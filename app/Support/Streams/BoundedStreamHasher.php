<?php

declare(strict_types=1);

namespace App\Support\Streams;

use Closure;

/**
 * Потоковое чтение, ограниченное по байтам, с опциональной записью в sink и подсчётом SHA-256.
 */
final readonly class BoundedStreamHasher
{
    /**
     * @param  resource  $source
     * @param  resource|null  $sink
     * @param  int<1, max>  $chunkSize
     * @param  (Closure(): void)|null  $onTick
     */
    public static function process(
        $source,
        int $maxBytes,
        $sink = null,
        int $chunkSize = 8192,
        bool $hash = true,
        bool $capReadLength = false,
        ?Closure $onTick = null,
    ): StreamHashResult {
        $context = $hash ? hash_init('sha256') : null;
        $bytes = 0;

        while (! feof($source)) {
            if ($onTick !== null) {
                $onTick();
            }

            $readLength = $capReadLength
                ? max(1, min($chunkSize, $maxBytes - $bytes + 1))
                : $chunkSize;

            $chunk = fread($source, $readLength);
            if ($chunk === false || ($chunk === '' && ! feof($source))) {
                throw new BoundedStreamException(BoundedStreamException::REASON_READ, 'Bounded stream read failed.');
            }

            $length = strlen($chunk);
            $bytes += $length;
            if ($bytes > $maxBytes) {
                throw new BoundedStreamException(BoundedStreamException::REASON_OVERFLOW, 'Bounded stream exceeded byte ceiling.');
            }

            if ($context !== null) {
                hash_update($context, $chunk);
            }

            if ($sink !== null && fwrite($sink, $chunk) !== $length) {
                throw new BoundedStreamException(BoundedStreamException::REASON_WRITE, 'Bounded stream write failed.');
            }
        }

        return new StreamHashResult(
            sha256: $context !== null ? hash_final($context) : '',
            bytes: $bytes,
        );
    }
}
