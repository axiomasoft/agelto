<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Exceptions\Procurement\Network\ResponseDecodeFailed;
use App\Exceptions\Procurement\Network\ResponseLimitExceeded;

/** Input is already transport-decompressed entity bytes. Never guesses gzip from magic bytes. */
final readonly class ResponseDecoder
{
    public function decode(string $bytes, ?string $contentType, RequestProfile $profile): DecodedTextBody
    {
        if ($profile->maxTextBytes === null) {
            throw new \LogicException('Binary bodies never enter the text decoder.');
        }
        if (strlen($bytes) > $profile->maxEntityBytes) {
            throw new ResponseLimitExceeded('Entity byte limit exceeded.');
        }
        $encoding = null;
        foreach (["\x00\x00\xFE\xFF" => 'UTF-32BE', "\xFF\xFE\x00\x00" => 'UTF-32LE', "\xEF\xBB\xBF" => 'UTF-8', "\xFE\xFF" => 'UTF-16BE', "\xFF\xFE" => 'UTF-16LE'] as $bom => $name) {
            if (str_starts_with($bytes, $bom)) {
                $encoding = $name;
                $bytes = substr($bytes, strlen($bom));
                break;
            }
        }
        if ($encoding === null && preg_match('/\bcharset\s*=\s*[\x22\x27]?([a-zA-Z0-9_.:-]+)/i', $contentType ?? '', $match) === 1) {
            $encoding = $match[1];
        }
        if ($encoding === null && $bytes !== '' && str_contains(strtolower($contentType ?? ''), 'html')) {
            $document = new \DOMDocument;
            $document->loadHTML(substr($bytes, 0, 4096), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($document->getElementsByTagName('meta') as $tag) {
                $charset = $tag->getAttribute('charset');
                if ($charset !== '') {
                    $encoding = $charset;
                    break;
                }
                if (strtolower($tag->getAttribute('http-equiv')) === 'content-type'
                    && preg_match('/\bcharset\s*=\s*[\x22\x27]?([a-zA-Z0-9_.:-]+)/i', $tag->getAttribute('content'), $match) === 1) {
                    $encoding = $match[1];
                    break;
                }
            }
        }
        $encoding ??= $profile->charsetDefault;
        if ($encoding === null) {
            throw new ResponseDecodeFailed('No declared text encoding.');
        }
        $supported = [];
        foreach (mb_list_encodings() as $name) {
            $supported[strtolower($name)] = $name;
            foreach (mb_encoding_aliases($name) as $alias) {
                $supported[strtolower($alias)] = $name;
            }
        }
        $canonical = $supported[strtolower($encoding)] ?? null;
        // Reject non-charset conversion pseudo encodings (BASE64/HTML-ENTITIES/7bit/8bit).
        if ($canonical === null || in_array(strtoupper($canonical), ['BASE64', 'UUENCODE', 'HTML-ENTITIES', 'QUOTED-PRINTABLE', '7BIT', '8BIT'], true)) {
            throw new ResponseDecodeFailed('Unsupported declared text encoding.');
        }
        if (! mb_check_encoding($bytes, $canonical)) {
            throw new ResponseDecodeFailed('Invalid bytes for declared text encoding.');
        }
        // Convert in bounded chunks: check UTF-8 expansion before assembling the final string.
        $text = '';
        $length = 0;
        foreach (mb_str_split($bytes, 16_384, $canonical) as $chunk) {
            $utf8 = mb_convert_encoding($chunk, 'UTF-8', $canonical);
            if ($utf8 === false || mb_convert_encoding($utf8, $canonical, 'UTF-8') !== $chunk) {
                throw new ResponseDecodeFailed('Text conversion failed.');
            }
            $length += strlen($utf8);
            if ($length > $profile->maxTextBytes) {
                throw new ResponseLimitExceeded('Normalized text byte limit exceeded.');
            }
            $text .= $utf8;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new ResponseDecodeFailed('Invalid normalized UTF-8.');
        }

        return new DecodedTextBody($text, $canonical);
    }
}
