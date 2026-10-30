<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Pages;

use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use App\Services\Procurement\Network\NetworkRouteCommands;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class EditProxy extends EditRecord
{
    protected static string $resource = ProxyResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $proxy = $this->getRecord();
        if (! $proxy instanceof Proxy) {
            throw new \LogicException('Expected proxy configuration.');
        }

        return [...$data, 'enabled' => $proxy->route?->enabled, 'secret_ref' => $proxy->route?->secret_ref];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        if (! $record instanceof Proxy) {
            throw new \LogicException('Expected proxy configuration.');
        }

        return app(NetworkRouteCommands::class)->saveProxy(
            actor: $actor, proxy: $record, configuration: ProxyNodeConfiguration::fromArray($data),
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
        );
    }
}
