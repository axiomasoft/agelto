<?php

declare(strict_types=1);

namespace App\Models\Procurement\Proxyma;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table(name: 'prc_network_route_profile_members', timestamps: false)]
final class LegacyProfileMember extends Model {}
