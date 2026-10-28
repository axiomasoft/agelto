<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * GCRA authority одного scope (origin/route-origin/probe). tat/send_tat/not_before могут быть
 * `-infinity`, поэтому остаются строками PostgreSQL, а не Carbon. Writer — только
 * `Repositories/Procurement/Network`; rate debt не перематывается.
 *
 * @property string $id
 * @property string $scope_key
 * @property int $emission_interval_us
 * @property int $burst
 * @property int $max_inflight
 * @property string $tat
 * @property string $send_tat
 * @property string $not_before
 * @property int $policy_version
 */
#[Table(name: 'prc_network_budgets', keyType: 'string', incrementing: false, timestamps: false)]
final class NetworkBudget extends Model {}
