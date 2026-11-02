<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\ProviderCall;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\RequestRepository;
use Illuminate\Support\Facades\DB;

/** Consumer supplies its transaction/locks; late factual capture deliberately needs no work lease. */
final readonly class RequestLedger
{
    public function __construct(private RequestRepository $requests, private ReceiptStore $receipts) {}

    public function authorize(ProviderOperationPlan $plan, string $attempt, int $generation, string $periodId, ProviderOperationIds $ids): AuthorizedProviderOperation
    {
        return $this->requests->authorize($plan, $attempt, $generation, $periodId, $ids);
    }

    public function capture(ProviderCall $call, int $status, string $body): VerifiedReceipt
    {
        $receipt = $this->receipts->put($call, $status, $body);
        DB::transaction(fn () => $this->requests->acceptReceiptLocked($receipt));

        return $receipt;
    }

    public function replay(ProviderCall $original, string $commandId, string $actor, string $reason): RequestIdentity
    {
        if ($this->receipts->inspect($original) !== null) {
            throw new OperationDenied('replay_requires_missing_receipt');
        }

        return $this->requests->replay($original, $commandId, $actor, $reason);
    }
}
