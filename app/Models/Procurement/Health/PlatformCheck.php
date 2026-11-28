<?php

declare(strict_types=1);

namespace App\Models\Procurement\Health;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Platform\Platform;
use Database\Factories\Procurement\Health\PlatformCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(PlatformCheckFactory::class)]
#[Fillable([
    'platform_id', 'check_kind', 'verdict', 'measured', 'baseline_sha256', 'window_from',
    'window_to', 'sample_size', 'requests_used', 'message', 'checked_at',
])]
#[Table(name: 'prc_platform_checks')]
final class PlatformCheck extends Model
{
    /** @use HasFactory<PlatformCheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'check_kind' => PlatformCheckKind::class,
            'verdict' => PlatformCheckVerdict::class,
            'measured' => 'array',
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'checked_at' => 'datetime',
            'sample_size' => 'integer',
            'requests_used' => 'integer',
        ];
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }
}
