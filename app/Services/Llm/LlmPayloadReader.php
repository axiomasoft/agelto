<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Dto\Llm\Reporting\StoredPayload;
use App\Exceptions\Llm\ReceiptUnavailable;
use App\Models\Llm\LlmCall;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ReceiptStore;

/** Authorized UI read of the verified provider body, never of the receipt's ownership envelope. */
final readonly class LlmPayloadReader
{
    private const int MAX_DISPLAY_BYTES = 262_144;

    public function __construct(private RequestRepository $requests, private ReceiptStore $receipts) {}

    public function receipt(LlmCall $call): StoredPayload
    {
        if ($call->receipt_state !== 'verified' || $call->receipt_key === null) {
            return StoredPayload::absent();
        }
        try {
            $verified = $this->receipts->inspect($this->requests->get($call->physical_call_id));
        } catch (ReceiptUnavailable) {
            return StoredPayload::unavailable();
        }
        if ($verified === null) {
            return StoredPayload::unavailable();
        }

        return StoredPayload::content(substr($verified->body, 0, self::MAX_DISPLAY_BYTES), strlen($verified->body) > self::MAX_DISPLAY_BYTES);
    }
}
