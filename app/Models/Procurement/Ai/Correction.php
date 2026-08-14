<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\Ai\CorrectionReason;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use Database\Factories\Procurement\Ai\CorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Структурированная операторская коррекция результата AI-анализа (P9.1, D15) — «что не так»
 * с JSONPath-адресом поля и enum-причиной; `hint` — переформулировка для подмешивания в промт
 * повторного прогона (P9.2), `is_reusable` распространяет её на все документы этого типа.
 *
 * @property string $uuid
 * @property string $content
 * @property string|null $hint
 * @property Carbon|null $applied_at
 */
#[UseFactory(CorrectionFactory::class)]
#[Fillable([
    'uuid',
    'analysis_id',
    'estimate_id',
    'document_type',
    'field_path',
    'reason',
    'content',
    'hint',
    'is_reusable',
    'created_by',
    'applied_at',
])]
#[Table(name: 'prc_ai_corrections')]
final class Correction extends Model
{
    /** @use HasFactory<CorrectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => CorrectionReason::class,
            'is_reusable' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(related: Analysis::class);
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(related: Estimate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'created_by');
    }

    protected static function booted(): void
    {
        self::creating(function (self $correction): void {
            $correction->uuid ??= (string) Str::uuid7();
        });
    }
}
