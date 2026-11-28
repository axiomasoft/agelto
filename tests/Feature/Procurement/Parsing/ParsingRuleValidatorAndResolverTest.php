<?php

declare(strict_types=1);

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Parsing\InvalidParsingRuleSetException;
use App\Exceptions\Procurement\Parsing\ParsingRuleSetDisabledException;
use App\Exceptions\Procurement\Parsing\ParsingRuleSetUnavailableException;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Parsing\ParsingRuleStoreRepository;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use App\Services\Procurement\Parsing\ParsingRuleSetResolver;
use App\Services\Procurement\Parsing\Schema\ParsingRuleSchemaRegistry;
use App\Services\Procurement\Parsing\Validation\ParsingRuleValidator;

function validListingPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'format' => 'csv',
        'transport' => [
            'method' => 'GET',
            'page_param' => 'page',
            'page_size_param' => 'size',
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
                    'header_aliases' => ['Номер'],
                    'required' => true,
                ],
            ],
        ],
        'detail_url_template' => 'https://example.test/card?regNumber={regNumber}',
    ], $overrides);
}

test('реестр схем загружает локальные JSON-схемы без внешних ссылок', function (): void {
    $registry = app(ParsingRuleSchemaRegistry::class);

    $listingSchema = $registry->loadSchemaObject(StageCode::Listing);
    $detailSchema = $registry->loadSchemaObject(StageCode::Detail);
    $statusSchema = $registry->loadSchemaObject(StageCode::StatusRefresh);

    expect($listingSchema)->toBeObject()
        ->and($detailSchema)->toBeObject()
        ->and($statusSchema)->toBe($detailSchema);
});

test('валидатор отклоняет невалидный CSS-селектор, небезопасный regex, некорректный URL-шаблон и превышение размера JSON', function (): void {
    $validator = app(ParsingRuleValidator::class);

    $validResult = $validator->validate(StageCode::Listing, validListingPayload());
    expect($validResult->valid)->toBeTrue();

    $badCss = $validator->validate(StageCode::Listing, validListingPayload([
        'transport' => ['item_link_selectors' => ['div[[[invalid']],
    ]));
    expect($badCss->valid)->toBeFalse();

    $badRegex = $validator->validate(StageCode::Listing, validListingPayload([
        'external_id_regex' => '/(a+)+$/u',
    ]));
    expect($badRegex->valid)->toBeFalse();

    $badUrl = $validator->validate(StageCode::Listing, validListingPayload([
        'detail_url_template' => 'https://{regNumber}.example.test/card',
    ]));
    expect($badUrl->valid)->toBeFalse();

    $oversized = $validator->validate(StageCode::Listing, validListingPayload([
        'columns' => [
            'columns' => [
                [
                    'target_field' => 'external_id',
                    'header_aliases' => [str_repeat('a', 263_000)],
                    'required' => true,
                ],
            ],
        ],
    ]));
    expect($oversized->valid)->toBeFalse();
});

test('менеджер публикует правила, фиксирует дрейф, а резолвер соблюдает fail-closed семантику', function (): void {
    $platform = Platform::factory()->create();
    $manager = app(ParsingRuleManager::class);
    $resolver = app(ParsingRuleSetResolver::class);
    $store = app(ParsingRuleStoreRepository::class);
    $resolver->clearCache();

    expect($resolver->resolve($platform, StageCode::Listing))->toBeNull();

    expect(fn () => $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Listing,
        rules: ['format' => 'csv'],
        changeSummary: 'Невалидные правила',
    ))->toThrow(InvalidParsingRuleSetException::class);

    $published = $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Listing,
        rules: validListingPayload(),
        changeSummary: 'Публикация рабочих правил',
    );

    $resolved = $resolver->resolve($platform, StageCode::Listing);
    expect($resolved)->not->toBeNull()
        ->and($resolved?->ruleVersionId)->toBe($published->id)
        ->and($resolved?->rules)->toBeInstanceOf(ListingRuleSetDto::class);

    $driftEvent = $manager->recordDriftDetected(
        ruleSetId: $published->rule_set_id,
        ruleVersionId: $published->id,
        signal: new DriftSignal('missing_column', StageCode::Listing, 'external_id', 'Номер', null, 'critical'),
    );
    expect($driftEvent->event_type)->toBe(ParsingRuleEventType::DriftDetected);

    $store->setEnabled($published->rule_set_id, false, ParsingRuleActorType::User, null, 'Отключено');
    expect(fn () => $resolver->resolve($platform, StageCode::Listing))
        ->toThrow(ParsingRuleSetDisabledException::class);

    $emptySet = $store->ensureRuleSet($platform->id, StageCode::Detail);
    expect($emptySet->active_version_id)->toBeNull();
    expect(fn () => $resolver->resolve($platform, StageCode::Detail))
        ->toThrow(ParsingRuleSetUnavailableException::class);
});
