<?php

declare(strict_types=1);

use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Platform\Capabilities\UnsupportedCapability;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\EndpointCatalog;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Definition\RegistryDefinition;
use App\Dto\Procurement\Platform\Definition\RegistryDefinitions;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Platform\Diagnostics\DiagnosticChecks;
use App\Dto\Procurement\Platform\Identity\AdapterVersion;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProtocolType;
use App\Exceptions\Procurement\Parsing\ParsingRegexExecutionException;
use App\Services\Procurement\Parsing\Validation\ParsingRuleValidator;
use App\Services\Procurement\Parsing\Validation\SafeRegex;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validationTestListingPayload(array $overrides = []): array
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validationTestDetailPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'format' => 'html',
        'envelope' => [
            'title_selectors' => ['h1.title'],
            'status_selectors' => ['span.status'],
        ],
        'routes' => [
            'common_info_path' => 'https://example.test/common?regNumber={regNumber}',
            'documents_path' => 'https://example.test/docs?regNumber={regNumber}',
        ],
    ], $overrides);
}

function validationTestPlatformDefinition(): PlatformDefinition
{
    $unsupported = new UnsupportedCapability('Validation fixture only.');

    return new PlatformDefinition(
        code: new PlatformCode('neutral_test'),
        version: new AdapterVersion('1'),
        registries: new RegistryDefinitions([
            new RegistryDefinition('main', new SourceIdentifierType('neutral:notice')),
        ]),
        endpoints: new EndpointCatalog([]),
        listing: $unsupported,
        detail: $unsupported,
        status: $unsupported,
        documents: $unsupported,
        checks: new DiagnosticChecks([]),
        sourceTargets: new SourceTargetDefinitions('neutral_test', [
            new SourceTargetDefinition(
                key: 'main',
                capability: 'detail',
                origin: CanonicalOrigin::fromUrl('https://example.test'),
                profiles: [
                    new RequestProfile('neutral.detail', 2, 5, 10, 2_097_152, 6_291_456, 3, 3, 2, [200, 600], ResponseKind::Text, 'UTF-8', 1),
                ],
            ),
        ]),
        partitioning: $unsupported,
    );
}

test('JSON Schema отклоняет лишние свойства, неверные типы и превышение лимита 256 КБ', function (): void {
    $validator = app(ParsingRuleValidator::class);

    $extraProperty = $validator->validate(StageCode::Listing, validationTestListingPayload([
        'unexpected_field' => 'forbidden',
    ]));
    expect($extraProperty->valid)->toBeFalse()
        ->and($extraProperty->issues)->not->toBeEmpty();

    $wrongType = $validator->validate(StageCode::Listing, validationTestListingPayload([
        'transport' => ['default_page_size' => 'fifty'],
    ]));
    expect($wrongType->valid)->toBeFalse()
        ->and($wrongType->issues)->not->toBeEmpty();

    $oversized = $validator->validate(StageCode::Listing, validationTestListingPayload([
        'columns' => [
            'columns' => [
                [
                    'target_field' => 'external_id',
                    'header_aliases' => [str_repeat('x', 262_500)],
                    'required' => true,
                ],
            ],
        ],
    ]));
    expect($oversized->valid)->toBeFalse()
        ->and(array_column($oversized->issues, 'code'))->toContain('json.size_exceeded');
});

test('валидатор отклоняет битые CSS-селекторы и псевдо-класс :contains', function (): void {
    $validator = app(ParsingRuleValidator::class);

    $brokenCss = $validator->validate(StageCode::Detail, validationTestDetailPayload([
        'envelope' => ['title_selectors' => ['div[[[broken']],
    ]));
    expect($brokenCss->valid)->toBeFalse();

    $containsPseudo = $validator->validate(StageCode::Detail, validationTestDetailPayload([
        'envelope' => ['title_selectors' => ['div:contains("Сумма")']],
    ]));
    expect($containsPseudo->valid)->toBeFalse()
        ->and(array_column($containsPseudo->issues, 'code'))->toContain('css.forbidden_pseudo_class');
});

test('SafeRegex отклоняет ReDoS-паттерны, x-флаг, PCRE-глаголы (*...), backreference и subject больше 64 КБ', function (): void {
    $unsafePatterns = [
        '/(a+)+$/u',
        '/foo bar/x',
        '/(*COMMIT)abc/',
        '/(a)\1/',
    ];

    foreach ($unsafePatterns as $pattern) {
        expect(fn () => SafeRegex::assertSafe($pattern))
            ->toThrow(InvalidArgumentException::class);
    }

    $safe = new RegexPattern('/[a-z]+/u');
    $oversizedSubject = str_repeat('a', 65_537);

    expect(fn () => SafeRegex::match($safe, $oversizedSubject))
        ->toThrow(ParsingRegexExecutionException::class);

    expect(fn () => SafeRegex::replace($safe, 'b', $oversizedSubject))
        ->toThrow(ParsingRegexExecutionException::class);
});

test('валидатор блокирует SSRF и origin bypass в URL-шаблонах правил', function (): void {
    $validator = app(ParsingRuleValidator::class);
    $definition = validationTestPlatformDefinition();

    $foreignOrigin = $validator->validate(
        StageCode::Listing,
        validationTestListingPayload([
            'detail_url_template' => 'https://attacker.tld/card?regNumber={regNumber}',
        ]),
        definition: $definition,
    );
    expect($foreignOrigin->valid)->toBeFalse()
        ->and(array_column($foreignOrigin->issues, 'code'))->toContain('url.origin_not_allowed');

    $placeholderInHost = $validator->validate(
        StageCode::Listing,
        validationTestListingPayload([
            'detail_url_template' => 'https://{regNumber}.example.test/card',
        ]),
        definition: $definition,
    );
    expect($placeholderInHost->valid)->toBeFalse()
        ->and(array_column($placeholderInHost->issues, 'code'))->toContain('url.placeholder_in_authority');
});

test('валидатор отклоняет невалидные значения enum для ролей, типов протоколов, статусов ставок и типов событий', function (): void {
    $validator = app(ParsingRuleValidator::class);

    $badEventRule = $validator->validate(StageCode::Detail, validationTestDetailPayload([
        'event_journal' => [
            'event_rules' => [
                ['event_type' => 'unknown_event_type', 'keywords' => ['тест']],
            ],
        ],
    ]));
    expect($badEventRule->valid)->toBeFalse();

    foreach ([
        ProcurementPartyRole::class,
        ProtocolType::class,
        BidStatus::class,
        ProcurementEventType::class,
    ] as $enumClass) {
        expect($enumClass::tryFrom('unknown_enum_value'))->toBeNull();
    }

    foreach ([
        'party_roles' => ['unknown_party_role'],
        'protocol_types' => ['unknown_protocol_type'],
        'bid_statuses' => ['unknown_bid_status'],
        'event_types' => ['unknown_event_type'],
    ] as $field => $values) {
        $result = $validator->validate(StageCode::Detail, validationTestDetailPayload([
            $field => $values,
        ]));
        expect($result->valid)->toBeFalse()
            ->and($result->issues)->not->toBeEmpty();
    }
});

test('DTO правил парсинга поддерживают round-trip сериализацию через fromArray и toArray', function (): void {
    $listingArray = validationTestListingPayload();
    $listingDto = ListingRuleSetDto::fromArray($listingArray);
    expect(ListingRuleSetDto::fromArray($listingDto->toArray()))->toEqual($listingDto);

    $detailArray = validationTestDetailPayload();
    $detailDto = DetailRuleSetDto::fromArray($detailArray);
    expect(DetailRuleSetDto::fromArray($detailDto->toArray()))->toEqual($detailDto);
});
