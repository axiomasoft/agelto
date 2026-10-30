<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Network;

use App\Dto\Procurement\Network\NetworkChangeResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Guards\Admin\Permissions\ProxyPermission;
use App\Models\User;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Network\NetworkRouteCommands;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Str;

final readonly class UpdateRouteProfile
{
    public function __construct(private RouteRepository $routes, private ProcessTransaction $transaction) {}

    /** @param list<string> $routeIds */
    public function execute(User $actor, string $key, int $expectedVersion, array $routeIds, string $reason): NetworkChangeResult
    {
        NetworkRouteCommands::authorize($actor, ProxyPermission::Update, $reason);
        [$event, $message] = [new ProcessUuid((string) Str::uuid()), new ProcessUuid((string) Str::uuid())];

        return $this->transaction->run(fn (): NetworkChangeResult => $this->routes->updateProfile($key, $expectedVersion, $routeIds, $actor->id, $reason, $event, $message));
    }
}
