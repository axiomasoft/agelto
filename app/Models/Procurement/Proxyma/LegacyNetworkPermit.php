<?php

declare(strict_types=1);

namespace App\Models\Procurement\Proxyma;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Только имя архивной таблицы для additive forward migrations; runtime callers запрещены. */
#[Table(name: 'prc_network_permits', keyType: 'string', incrementing: false, timestamps: false)]
final class LegacyNetworkPermit extends Model {}
