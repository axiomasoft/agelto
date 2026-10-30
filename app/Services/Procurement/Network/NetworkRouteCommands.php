<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Guards\Admin\Permissions\ProxyPermission;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/** Authorized commands; route state, version fencing and audit are one repository transaction. */
final readonly class NetworkRouteCommands
{
    public function __construct(private RouteRepository $routes, private ProcessTransaction $transaction,
        private SourceCatalogRepository $catalog, private OutboundPolicy $policy) {}

    public function saveProxy(User $actor, ?Proxy $proxy, ProxyNodeConfiguration $configuration, string $reason): Proxy
    {
        self::authorize($actor, $proxy === null ? ProxyPermission::Create : ProxyPermission::Update, $reason);
        [$event, $message] = [new ProcessUuid((string) Str::uuid()), new ProcessUuid((string) Str::uuid())];

        return $this->transaction->run(function () use ($proxy, $configuration, $actor, $reason, $event, $message): Proxy {
            $saved = $this->routes->storeProxy($proxy, $configuration, $actor->id, $reason, $event, $message);
            $this->catalog->initializeRoute($saved->route_id, $this->policy);

            return $saved;
        });
    }

    public function reactivate(User $actor, Proxy $proxy, string $reason): void
    {
        self::authorize($actor, ProxyPermission::Update, $reason);
        [$event, $message] = [new ProcessUuid((string) Str::uuid()), new ProcessUuid((string) Str::uuid())];
        $this->transaction->run(fn () => $this->routes->reactivate(new ProcessUuid($proxy->route_id), $actor->id, $reason, $event, $message));
    }

    public static function authorize(User $actor, ProxyPermission $permission, string $reason): void
    {
        if (! $actor->hasPermission(permission: $permission, panelId: 'admin')) {
            throw new AuthorizationException('Network route command is not permitted.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A network route command requires an audit reason.');
        }
    }
}
