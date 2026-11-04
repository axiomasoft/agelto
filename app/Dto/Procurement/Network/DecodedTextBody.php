<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/**
 * Текст после transport decompression и проверенного charset decoding. sourceCharset —
 * выбранная кодировка (BOM/header/meta/default); replacement characters не маскируют ошибку.
 */
final readonly class DecodedTextBody implements ResponseBody
{
    public function __construct(public string $utf8, public string $sourceCharset)
    {
        if (! mb_check_encoding($utf8, 'UTF-8')) {
            throw new \InvalidArgumentException('Decoded text body must be valid UTF-8.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,39}$/D', $sourceCharset) !== 1) {
            throw new \InvalidArgumentException('Invalid source charset label.');
        }
    }
}
