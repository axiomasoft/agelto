<?php

declare(strict_types=1);

use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Parsing\Validation\RuleValidationResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Repositories\Procurement\Parsing\ParsingRuleStoreRepository;

function sampleListingRules(string $alias = 'Номер закупки'): array
{
    return [
        'format' => 'csv',
        'transport' => [
            'method' => 'GET',
            'page_param' => 'pageNumber',
            'page_size_param' => 'recordsPerPage',
            'default_page_size' => 50,
            'item_link_selectors' => null,
            'next_page_selectors' => null,
        ],
        'date_parsing' => [
            'formats' => ['d.m.Y'],
            'timezone' => 'Europe/Moscow',
            'extract_regex' => null,
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
                    'header_aliases' => [$alias],
                    'column_index' => null,
                    'required' => true,
                    'value_regex' => null,
                ],
            ],
            'row_selectors' => null,
            'deduplicate_by_field' => null,
        ],
        'json_listing' => null,
        'external_id_regex' => null,
        'detail_url_template' => null,
    ];
}

test('репозиторий записи создаёт набор правил, версии, активирует и выполняет откат через клонирование в новую версию', function (): void {
    $platform = Platform::factory()->create();
    $store = app(ParsingRuleStoreRepository::class);
    $read = app(ParsingRuleReadRepository::class);

    $ruleSet = $store->ensureRuleSet($platform->id, StageCode::Listing);
    $ruleSetAgain = $store->ensureRuleSet($platform->id, StageCode::Listing);
    expect($ruleSetAgain->id)->toBe($ruleSet->id);

    $rulesV1 = sampleListingRules('Номер');
    $hashV1 = ContentHash::of(json_encode($rulesV1, JSON_THROW_ON_ERROR));
    $report = (new RuleValidationResult(true, 'agelto.parsing.listing.v1', 1))->toArray();

    $v1 = $store->createVersion(
        ruleSetId: $ruleSet->id,
        schemaId: 'agelto.parsing.listing.v1',
        schemaVersion: 1,
        rulesJson: $rulesV1,
        rulesHash: $hashV1,
        source: ParsingRuleSource::Manual,
        changeSummary: 'Первая версия',
        status: ParsingRuleVersionStatus::Validated,
        validationReport: $report,
    );

    expect($v1->version)->toBe(1)
        ->and($v1->rules_hash)->toBe($hashV1->hex)
        ->and($v1->rules)->toBeInstanceOf(ListingRuleSetDto::class)
        ->and($v1->validationReportDto)->toBeInstanceOf(RuleValidationResult::class);

    $activatedV1 = $store->activateVersion($ruleSet->id, $v1->id, ParsingRuleActorType::Cli, null, 'Активация v1');
    expect($activatedV1->status)->toBe(ParsingRuleVersionStatus::Active);

    $rulesV2 = sampleListingRules('Реестровый номер');
    $hashV2 = ContentHash::of(json_encode($rulesV2, JSON_THROW_ON_ERROR));
    $v2 = $store->createVersion(
        ruleSetId: $ruleSet->id,
        schemaId: 'agelto.parsing.listing.v1',
        schemaVersion: 1,
        rulesJson: $rulesV2,
        rulesHash: $hashV2,
        source: ParsingRuleSource::Manual,
        changeSummary: 'Вторая версия',
        status: ParsingRuleVersionStatus::Validated,
        validationReport: $report,
    );

    $store->activateVersion($ruleSet->id, $v2->id, ParsingRuleActorType::Cli, null, 'Активация v2');
    expect($v1->refresh()->status)->toBe(ParsingRuleVersionStatus::Superseded);

    $v3Rollback = $store->rollbackToVersion($ruleSet->id, $v1->id, ParsingRuleActorType::User, null, 'Откат на v1');
    expect($v3Rollback->version)->toBe(3)
        ->and($v3Rollback->status)->toBe(ParsingRuleVersionStatus::Active)
        ->and($v3Rollback->source)->toBe(ParsingRuleSource::Rollback)
        ->and($v3Rollback->parent_version_id)->toBe($v1->id)
        ->and($v3Rollback->rules_hash)->toBe($hashV1->hex)
        ->and($v2->refresh()->status)->toBe(ParsingRuleVersionStatus::Superseded);

    $foundByHash = $read->findByHash($ruleSet->id, $hashV1);
    expect($foundByHash)->not->toBeNull()
        ->and($read->listVersions($ruleSet->id))->toHaveCount(3);

    $events = $read->listEvents($ruleSet->id);
    expect($events->pluck('event_type')->map(fn (ParsingRuleEventType $t): string => $t->value)->all())
        ->toContain('created', 'validated', 'activated', 'rolled_back');
});

test('репозиторий чтения находит точный реестр, fallback на null-реестр и fallback status_refresh на detail', function (): void {
    $platform = Platform::factory()->create();
    $store = app(ParsingRuleStoreRepository::class);
    $read = app(ParsingRuleReadRepository::class);

    $detailSet = $store->ensureRuleSet($platform->id, StageCode::Detail, null, 'default', AutoHealMode::Disabled);
    $detailRules = [
        'format' => 'html',
        'envelope' => [
            'title_selectors' => ['h1.title'],
            'number_selectors' => null,
            'status_selectors' => null,
            'price_selectors' => null,
            'currency_selectors' => null,
            'law_selectors' => null,
            'not_found_markers' => [],
        ],
    ];
    $detailHash = ContentHash::of(json_encode($detailRules, JSON_THROW_ON_ERROR));
    $detailVer = $store->createVersion(
        ruleSetId: $detailSet->id,
        schemaId: 'agelto.parsing.detail.v1',
        schemaVersion: 1,
        rulesJson: $detailRules,
        rulesHash: $detailHash,
        source: ParsingRuleSource::Seed,
        changeSummary: 'Детальный сид',
        status: ParsingRuleVersionStatus::Validated,
    );
    $store->activateVersion($detailSet->id, $detailVer->id, ParsingRuleActorType::Seeder, null, 'Seed');

    $pointer = $read->findActivePointer($platform->id, StageCode::StatusRefresh, 'reg_custom');
    expect($pointer)->not->toBeNull()
        ->and($pointer['rule_set_id'])->toBe($detailSet->id)
        ->and($pointer['active_version_id'])->toBe($detailVer->id)
        ->and($pointer['rules_hash_hex'])->toBe($detailHash->hex);
});

test('фабрики моделей правил парсинга создают валидные записи в базе данных', function (): void {
    $ruleSet = ParsingRuleSet::factory()->create();
    $version = ParsingRuleVersion::factory()->create(['rule_set_id' => $ruleSet->id]);

    expect($version->ruleSet->id)->toBe($ruleSet->id)
        ->and($version->rules)->toBeInstanceOf(ListingRuleSetDto::class);
});
