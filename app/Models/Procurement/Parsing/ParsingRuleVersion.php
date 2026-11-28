<?php

declare(strict_types=1);

namespace App\Models\Procurement\Parsing;

use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\StageParsingRules;
use App\Dto\Procurement\Parsing\Validation\RuleValidationIssue;
use App\Dto\Procurement\Parsing\Validation\RuleValidationResult;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Support\Files\Sha256Cast;
use Carbon\CarbonImmutable;
use Database\Factories\Procurement\Parsing\ParsingRuleVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnexpectedValueException;

/**
 * Иммутабельный снимок версии набора правил парсинга.
 *
 * @property int $id
 * @property string $uuid
 * @property int $rule_set_id
 * @property int $version
 * @property string $schema_id
 * @property int $schema_version
 * @property ParsingRuleVersionStatus $status
 * @property array<string, mixed> $rules_json
 * @property string $rules_hash
 * @property ParsingRuleSource $source
 * @property string $change_summary
 * @property array<string, mixed>|null $validation_report
 * @property int|null $parent_version_id
 * @property int|null $created_by
 * @property int|null $activated_by
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable $created_at
 * @property-read StageParsingRules $rules
 * @property-read RuleValidationResult|null $validationReportDto
 * @property-read ParsingRuleSet $ruleSet
 * @property-read ParsingRuleVersion|null $parentVersion
 * @property-read Collection<int, ParsingRuleEvent> $events
 */
#[UseFactory(ParsingRuleVersionFactory::class)]
#[Fillable([
    'uuid',
    'rule_set_id',
    'version',
    'schema_id',
    'schema_version',
    'status',
    'rules_json',
    'rules_hash',
    'source',
    'change_summary',
    'validation_report',
    'parent_version_id',
    'created_by',
    'activated_by',
    'activated_at',
    'deactivated_at',
    'created_at',
])]
#[Table(name: 'prc_parsing_rule_versions')]
final class ParsingRuleVersion extends Model
{
    /** @use HasFactory<ParsingRuleVersionFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'status' => ParsingRuleVersionStatus::class,
            'rules_json' => 'array',
            'rules_hash' => Sha256Cast::class,
            'source' => ParsingRuleSource::class,
            'validation_report' => 'array',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ParsingRuleSet, $this>
     */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ParsingRuleSet::class, 'rule_set_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    /**
     * @return HasMany<ParsingRuleEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ParsingRuleEvent::class, 'rule_version_id');
    }

    /**
     * @return Attribute<StageParsingRules, never>
     */
    protected function rules(): Attribute
    {
        return Attribute::get(fn (): StageParsingRules => $this->resolveRules());
    }

    private function resolveRules(): StageParsingRules
    {
        return match ($this->schema_id) {
            'agelto.parsing.listing.v1' => ListingRuleSetDto::fromArray($this->rules_json),
            'agelto.parsing.detail.v1' => DetailRuleSetDto::fromArray($this->rules_json),
            default => throw new UnexpectedValueException(sprintf(
                'Unsupported parsing rule schema_id "%s".',
                $this->schema_id,
            )),
        };
    }

    /**
     * @return Attribute<RuleValidationResult|null, never>
     */
    protected function validationReportDto(): Attribute
    {
        return Attribute::get(function (): ?RuleValidationResult {
            $report = $this->validation_report;
            if ($report === null) {
                return null;
            }

            $valid = $report['valid'] ?? null;
            $schemaId = $report['schema_id'] ?? null;
            $schemaVersion = $report['schema_version'] ?? null;
            $rawIssues = $report['issues'] ?? [];

            if (! is_bool($valid) || ! is_string($schemaId) || ! is_int($schemaVersion) || ! is_array($rawIssues)) {
                throw new UnexpectedValueException('Invalid validation_report payload.');
            }

            $issues = [];
            foreach ($rawIssues as $item) {
                if (! is_array($item)) {
                    throw new UnexpectedValueException('Invalid validation_report issue item.');
                }
                $severity = $item['severity'] ?? null;
                $code = $item['code'] ?? null;
                $path = $item['path'] ?? null;
                $message = $item['message'] ?? null;
                if (! is_string($severity) || ! is_string($code) || ! is_string($path) || ! is_string($message)) {
                    throw new UnexpectedValueException('Invalid validation_report issue fields.');
                }
                $issues[] = new RuleValidationIssue(
                    severity: $severity,
                    code: $code,
                    path: $path,
                    message: $message,
                );
            }

            return new RuleValidationResult(
                valid: $valid,
                schemaId: $schemaId,
                schemaVersion: $schemaVersion,
                issues: $issues,
            );
        });
    }
}
