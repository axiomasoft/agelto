<?php

declare(strict_types=1);

namespace App\Models\Procurement\Proxyma;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Только имя архивной таблицы для additive forward migrations; runtime callers запрещены. */
#[Table(name: 'prc_route_target_health', keyType: 'string', incrementing: false, timestamps: false)]
final class LegacyRouteTargetHealth extends Model {}
