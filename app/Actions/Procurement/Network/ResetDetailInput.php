<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Network;

use App\Guards\Admin\Permissions\ProcurementPermission;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ResetDetailInput
{
    public function __construct(private ProcurementStoreRepository $store, private ProcessTransaction $transaction) {}

    public function execute(User $actor, Procurement $procurement, string $reason): void
    {
        if (! $actor->hasPermission(permission: ProcurementPermission::Update, panelId: 'admin')) {
            throw new AuthorizationException('Detail input recovery is not permitted.');
        }
        $this->transaction->run(fn () => $this->store->resetDetailInput($procurement, $actor->id, $reason));
    }
}
