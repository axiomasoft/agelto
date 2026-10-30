<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read projection; mutations go through the audited network repository.
 *
 * @property string $id
 * @property string $profile_key
 * @property int $policy_version
 */
#[Table(name: 'prc_network_route_profiles', keyType: 'string', incrementing: false, timestamps: false)]
final class NetworkRouteProfile extends Model
{
    protected function casts(): array
    {
        return ['policy_version' => 'integer'];
    }
}
