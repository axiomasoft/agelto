<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\Http\DecodedTextBody;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Exceptions\Procurement\Network\ResponseDecodeFailed;
use App\Exceptions\Procurement\Network\ResponseLimitExceeded;
use Proxyma\Domain\Execution\ResponseProfile;

/** Input is already transport-decompressed entity bytes. Never guesses gzip from magic bytes. */
final readonly class ResponseDecoder
{
    public function decode(string $bytes, ?string $contentType, RequestProfile $profile): DecodedTextBody
    {
        if ($profile->maxTextBytes === null) {
            throw new \LogicException('Binary bodies never enter the text decoder.');
        }
        try {
            $decoded = app(\Proxyma\Application\Execution\ResponseDecoder::class)->decode($bytes, $contentType,
                new ResponseProfile('text', $profile->maxEntityBytes,
                    $profile->maxEntityBytes, $profile->maxTextBytes, $profile->charsetDefault));
        } catch (\LengthException $error) {
            throw new ResponseLimitExceeded($error->getMessage(), previous: $error);
        } catch (\LogicException $error) {
            throw new ResponseDecodeFailed($error->getMessage(), previous: $error);
        }

        return new DecodedTextBody($decoded->text, $decoded->encoding);
    }
}
