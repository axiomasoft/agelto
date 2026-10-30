<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Network;

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\NetworkChangeResult;
use App\Guards\Admin\Permissions\SourceCircuitPermission;
use App\Models\User;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ResetAdapterHealth
{
    public function __construct(private AdapterHealthRepository $adapters, private ProcessTransaction $transaction) {}

    public function execute(User $actor, AdapterContractRef $contract, int $expectedVersion, string $reason): NetworkChangeResult
    {
        if (! $actor->hasPermission(permission: SourceCircuitPermission::Update, panelId: 'admin')) {
            throw new AuthorizationException('Adapter recovery is not permitted.');
        }

        return $this->transaction->run(fn (): NetworkChangeResult => $this->adapters->reset($contract, $expectedVersion, $actor->id, $reason));
    }
}
