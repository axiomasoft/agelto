<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\ScopeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Всегда существующая control authority row: default и manual override в одной строке,
 * отдельной prc_process_controls нет. Parent/key immutable; только system — корень.
 *
 * @property string $id
 * @property ScopeKind $scope_kind
 * @property string $scope_key
 * @property string|null $parent_id
 * @property ControlMode $desired_mode
 * @property int $control_version
 * @property int|null $changed_by
 * @property string|null $change_reason
 * @property CarbonImmutable|null $changed_at
 * @property-read ControlScope|null $parent
 */
#[Table(name: 'prc_control_scopes', keyType: 'string', incrementing: false, timestamps: false)]
final class ControlScope extends Model
{
    /** @return BelongsTo<ControlScope, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return [
            'scope_kind' => ScopeKind::class,
            'desired_mode' => ControlMode::class,
            'changed_at' => 'immutable_datetime',
        ];
    }
}
