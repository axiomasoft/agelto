<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Parsing;

use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParsingRuleVersion>
 */
final class ParsingRuleVersionFactory extends Factory
{
    protected $model = ParsingRuleVersion::class;

    public function definition(): array
    {
        $rules = [
            'format' => 'csv',
            'transport' => [
                'method' => 'GET',
                'page_param' => 'pageNumber',
                'page_size_param' => 'recordsPerPage',
                'default_page_size' => 50,
            ],
            'date_parsing' => [
                'formats' => ['d.m.Y'],
                'timezone' => 'Europe/Moscow',
            ],
            'csv_dialect' => [
                'delimiter' => ';',
                'enclosure' => '"',
                'escape' => '\\',
                'encoding' => 'UTF-8',
                'strip_bom' => true,
                'skip_empty_rows' => true,
                'header_row_index' => 0,
            ],
            'columns' => [
                'columns' => [
                    [
                        'target_field' => 'external_id',
                        'header_aliases' => ['Номер закупки'],
                        'required' => true,
                    ],
                ],
            ],
        ];

        return [
            'uuid' => (string) Str::uuid(),
            'rule_set_id' => ParsingRuleSet::factory(),
            'version' => 1,
            'schema_id' => 'agelto.parsing.listing.v1',
            'schema_version' => 1,
            'status' => ParsingRuleVersionStatus::Draft,
            'rules_json' => $rules,
            'rules_hash' => hash('sha256', (string) json_encode($rules, JSON_THROW_ON_ERROR)),
            'source' => ParsingRuleSource::Manual,
            'change_summary' => 'Initial version',
            'validation_report' => null,
            'parent_version_id' => null,
            'created_by' => null,
            'activated_by' => null,
            'activated_at' => null,
            'deactivated_at' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
