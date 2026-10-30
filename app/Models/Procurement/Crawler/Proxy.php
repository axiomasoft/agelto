<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Network\NetworkRoute;
use Database\Factories\Procurement\Crawler\ProxyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration only. Administrative state and health belong to the unique canonical route.
 *
 * @property int $id
 * @property string $route_id
 * @property string $service
 * @property string $host
 * @property int $port
 * @property string|null $username
 * @property string|null $country
 * @property-read NetworkRoute|null $route
 */
#[UseFactory(ProxyFactory::class)]
#[Fillable(['service', 'host', 'port', 'username', 'country', 'scopes'])]
#[Table(name: 'prc_proxies')]
final class Proxy extends Model
{
    /** @use HasFactory<ProxyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['port' => 'integer', 'scopes' => AsEnumCollection::of(ProxyScope::class)];
    }

    /** @return BelongsTo<NetworkRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(NetworkRoute::class, 'route_id');
    }
}
