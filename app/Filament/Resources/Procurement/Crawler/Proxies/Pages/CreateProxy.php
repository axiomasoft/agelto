<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Pages;

use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Models\User;
use App\Services\Procurement\Network\NetworkRouteCommands;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CreateProxy extends CreateRecord
{
    protected static string $resource = ProxyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return app(NetworkRouteCommands::class)->saveProxy(
            actor: $actor, proxy: null, configuration: ProxyNodeConfiguration::fromArray($data),
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
        );
    }
}
