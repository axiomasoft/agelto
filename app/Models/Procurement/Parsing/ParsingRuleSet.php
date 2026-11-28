<?php

declare(strict_types=1);

namespace App\Models\Procurement\Parsing;

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Platform\Platform;
use Carbon\CarbonImmutable;
use Database\Factories\Procurement\Parsing\ParsingRuleSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Набор версионированных правил парсинга для площадки, стадии и реестра.
 *
 * @property int $id
 * @property string $uuid
 * @property int $platform_id
 * @property StageCode $stage
 * @property string|null $registry_code
 * @property string $scope_key
 * @property int|null $active_version_id
 * @property int $current_version_number
 * @property AutoHealMode $auto_heal_mode
 * @property string|null $description
 * @property bool $is_enabled
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Platform $platform
 * @property-read ParsingRuleVersion|null $activeVersion
 * @property-read Collection<int, ParsingRuleVersion> $versions
 * @property-read Collection<int, ParsingRuleEvent> $events
 */
#[UseFactory(ParsingRuleSetFactory::class)]
#[Fillable([
    'uuid',
    'platform_id',
    'stage',
    'registry_code',
    'scope_key',
    'active_version_id',
    'current_version_number',
    'auto_heal_mode',
    'description',
    'is_enabled',
])]
#[Table(name: 'prc_parsing_rule_sets')]
final class ParsingRuleSet extends Model
{
    /** @use HasFactory<ParsingRuleSetFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'stage' => StageCode::class,
            'auto_heal_mode' => AutoHealMode::class,
            'is_enabled' => 'boolean',
            'current_version_number' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Platform, $this>
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'platform_id');
    }

    /**
     * @return BelongsTo<ParsingRuleVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(ParsingRuleVersion::class, 'active_version_id');
    }

    /**
     * @return HasMany<ParsingRuleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ParsingRuleVersion::class, 'rule_set_id');
    }

    /**
     * @return HasMany<ParsingRuleEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ParsingRuleEvent::class, 'rule_set_id');
    }
}
