<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Procurement\Parsing\Primitives\ColumnMappingRule;
use App\Dto\Procurement\Parsing\Primitives\EventClassificationRule;
use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\CardDomRule;
use App\Dto\Procurement\Parsing\Rules\CardEnvelopeRule;
use App\Dto\Procurement\Parsing\Rules\CardRoutesRule;
use App\Dto\Procurement\Parsing\Rules\CommonInfoRule;
use App\Dto\Procurement\Parsing\Rules\CsvDialectRule;
use App\Dto\Procurement\Parsing\Rules\DateParsingRule;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\DocumentsRule;
use App\Dto\Procurement\Parsing\Rules\EventJournalRule;
use App\Dto\Procurement\Parsing\Rules\ListingColumnsRule;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\ListingTransportRule;
use App\Dto\Procurement\Parsing\Rules\PartiesRule;
use App\Dto\Procurement\Parsing\Rules\SupplierResultsRule;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Platforms\Eis\EisProcedureType;
use App\Enums\Procurement\Parsing\ParsingFormat;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProtocolType;

/**
 * Единый источник дефолтных правил парсинга площадки ЕИС (44-ФЗ).
 *
 * Используется для первичного сидирования БД и в качестве fallback при автономном
 * инстанцировании парсеров без активного набора правил из БД.
 */
final readonly class EisDefaultParsingRules
{
    public const string LISTING_CONTENT_TYPE_REGEX = '~^(?:text/(?:csv|plain)|application/(?:csv|download|octet-stream))(?:;|$)~i';

    public const string DETAIL_CONTENT_TYPE_REGEX = '~^text/html(?:;|$)~i';

    /** @var list<int> */
    public const array WAF_HTTP_STATUSES = [403, 429];

    public const int WAF_RETRY_DELAY_SECONDS = 60;

    /** @var array<string, string> */
    public const array LISTING_QUERY_FLAGS = [
        'fz44' => 'on',
        'sortDirection' => 'true',
        'registryNumberCsv' => 'on',
        'publishDateCsv' => 'on',
        'lastDateChangeCsv' => 'on',
        'maxContractPriceCsv' => 'on',
        'nameOrderCsv' => 'on',
        'stepOrderPlacementCsv' => 'on',
        'currencyCodeCsv' => 'on',
        'methodOrderPurchaseCsv' => 'on',
        'purchaseNumbersCsv' => 'on',
        'startDateRequestCsv' => 'on',
        'endDateRequestCsv' => 'on',
        'customerNameCsv' => 'on',
    ];

    /** @var list<string> */
    public const array LISTING_REQUIRED_HEADERS = [
        'Реестровый номер закупки',
        'Дата размещения',
        'Дата обновления',
        'Начальная (максимальная) цена контракта',
    ];

    /** @var list<string> */
    public const array NO_BIDS_EXPECTED_PHRASES = [
        'не подано ни одной заявки',
        'все поданные заявки отклонены',
        'заявка не соответствует требованиям',
        'признан уклонившимся',
        'признаны уклонившимися',
        'отказался от заключения контракта',
    ];

    /** @var array<string, ProcurementPartyRole> */
    public const array PARTY_ROLE_MAP = [
        'Заказчик' => ProcurementPartyRole::Customer,
        'Организация, осуществляющая закупки в соответствии с частями 4, 4.3 или 4.4 статьи 15 Федерального закона № 44-ФЗ' => ProcurementPartyRole::Customer,
        'Специализированная некоммерческая организация, которая осуществляет деятельность, направленную на обеспечение проведения капитального ремонта общего имущества в многоквартирных домах в соответствии с Постановлением № 615 (региональный оператор)' => ProcurementPartyRole::Customer,
        'Организация, осуществляющая полномочия заказчика на осуществление закупок на основании соглашения в соответствии с частью 6 статьи 15 Федерального закона № 44-ФЗ' => ProcurementPartyRole::AuthorizedInstitution,
        'Размещающая организация' => ProcurementPartyRole::Placer,
        'Уполномоченный орган' => ProcurementPartyRole::AuthorizedBody,
        'Уполномоченное учреждение' => ProcurementPartyRole::AuthorizedInstitution,
        'Специализированная организация' => ProcurementPartyRole::SpecializedOrganization,
        'Оператор электронной площадки' => ProcurementPartyRole::PlatformOperator,
    ];

    /** @var array<string, ProtocolType> */
    public const array PROTOCOL_TYPE_MAP = [
        'рассмотрения первых частей' => ProtocolType::FirstPartsReview,
        'рассмотрения вторых частей' => ProtocolType::SecondPartsReview,
        'проведения аукциона' => ProtocolType::Auction,
        'оценки' => ProtocolType::Evaluation,
        'подведения итогов' => ProtocolType::Summary,
        'о признании закупки несостоявшейся' => ProtocolType::Failed,
        'обоснования выбора единственного поставщика' => ProtocolType::SingleSupplierJustification,
    ];

    public static function listing(): ListingRuleSetDto
    {
        return new ListingRuleSetDto(
            format: ParsingFormat::Csv,
            transport: new ListingTransportRule(
                method: 'GET',
                pageParam: 'from',
                pageSizeParam: 'to',
                defaultPageSize: 500,
            ),
            dateParsing: new DateParsingRule(
                formats: ['!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y'],
                timezone: 'Europe/Moscow',
            ),
            csvDialect: new CsvDialectRule(
                delimiter: ';',
                enclosure: '"',
                escape: '\\',
                encoding: 'UTF-8',
                stripBom: true,
                skipEmptyRows: true,
                headerRowIndex: 0,
            ),
            columns: new ListingColumnsRule(
                columns: [
                    new ColumnMappingRule(
                        targetField: 'externalId',
                        headerAliases: ['Реестровый номер закупки'],
                        required: true,
                    ),
                    new ColumnMappingRule(
                        targetField: 'title',
                        headerAliases: ['Наименование закупки'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'sourceStage',
                        headerAliases: ['Этап закупки'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'publishedAt',
                        headerAliases: ['Дата размещения'],
                        required: true,
                    ),
                    new ColumnMappingRule(
                        targetField: 'updatedAt',
                        headerAliases: ['Дата обновления'],
                        required: true,
                    ),
                    new ColumnMappingRule(
                        targetField: 'initialPrice',
                        headerAliases: ['Начальная (максимальная) цена контракта'],
                        required: true,
                    ),
                    new ColumnMappingRule(
                        targetField: 'currency',
                        headerAliases: ['Валюта'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'procedureTypeRaw',
                        headerAliases: ['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'biddingStartsAt',
                        headerAliases: ['Начало подачи заявок'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'biddingEndsAt',
                        headerAliases: ['Окончание подачи заявок'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'customerName',
                        headerAliases: ['Наименование Заказчика'],
                        required: false,
                    ),
                    new ColumnMappingRule(
                        targetField: 'ikz',
                        headerAliases: ['Идентификационный код закупки (ИКЗ)'],
                        required: false,
                    ),
                ],
            ),
            externalIdRegex: new RegexPattern('/^[0-9]{1,200}$/'),
            detailUrlTemplate: 'https://zakupki.gov.ru/epz/order/notice/{type}/view/common-info.html?regNumber={regNumber}',
        );
    }

    public static function detail(): DetailRuleSetDto
    {
        return new DetailRuleSetDto(
            format: ParsingFormat::Html,
            routes: new CardRoutesRule(
                commonInfoPath: '/epz/order/notice/{type}/view/common-info.html?regNumber={regNumber}',
                documentsPath: '/epz/order/notice/{type}/view/documents.html?regNumber={regNumber}',
                journalPath: '/epz/order/notice/{type}/view/event-journal.html?regNumber={regNumber}',
                supplierResultsPath: '/epz/order/notice/{type}/view/supplier-results.html?regNumber={regNumber}',
            ),
            envelope: new CardEnvelopeRule(
                titleSelectors: SelectorChain::fromArray([
                    '.cardMainInfo__purchaseObject',
                    '.cardMainInfo__section .cardMainInfo__content',
                ]),
                numberSelectors: SelectorChain::fromArray(['.cardMainInfo__purchaseLink']),
                statusSelectors: SelectorChain::fromArray(['.cardMainInfo__state']),
                priceSelectors: SelectorChain::fromArray(['.cardMainInfo__content.cost', '.price']),
                notFoundMarkers: [
                    'Запрашиваемая страница не найдена',
                    'Информация о закупке не найдена',
                ],
            ),
            dom: new CardDomRule(
                sectionSelectors: [
                    'block_info' => SelectorChain::fromArray(['section.blockInfo__section']),
                    'organizations' => SelectorChain::fromArray(['section.organizations-block']),
                    'lots' => SelectorChain::fromArray(['.lots-block']),
                ],
                fieldRowsSelectors: SelectorChain::fromArray(['section.blockInfo__section']),
                labelSelectors: SelectorChain::fromArray(['span.section__title']),
                valueSelectors: SelectorChain::fromArray(['span.section__info']),
            ),
            commonInfo: new CommonInfoRule(
                fieldAliases: [
                    'ikz' => ['Идентификационный код закупки (ИКЗ)'],
                    'etp_name' => ['Наименование электронной площадки в информационно-телекоммуникационной сети «Интернет»'],
                    'etp_url' => ['Адрес электронной площадки в информационно-телекоммуникационной сети «Интернет»'],
                    'contact_name' => ['Ответственное должностное лицо'],
                    'contact_email' => ['Адрес электронной почты'],
                    'financing_total' => ['Финансовое обеспечение закупки (всего)'],
                ],
                dateFields: ['published_at', 'updated_at', 'bidding_starts_at', 'bidding_ends_at'],
                moneyFields: ['initial_price', 'financing_total'],
                dateParsing: new DateParsingRule(
                    formats: ['!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y'],
                    timezone: 'Europe/Moscow',
                ),
            ),
            parties: new PartiesRule(
                customerSectionSelectors: SelectorChain::fromArray([
                    'section.organizations-block table.parties-table',
                    '.lots-block',
                ]),
                customerNameSelectors: SelectorChain::fromArray(['td:nth-child(1)', 'span.section__info']),
                customerInnSelectors: SelectorChain::fromArray(['td:nth-child(2)', 'span.section__info']),
                innRegex: new RegexPattern('/\bИНН\s*:?\s*(\d{12}|\d{10})(?!\d)/ui'),
                kppRegex: new RegexPattern('/\bКПП\s*:?\s*(\d{9})(?!\d)/ui'),
                ogrnRegex: new RegexPattern('/\bОГРН\s*:?\s*(\d{15}|\d{13})(?!\d)/ui'),
            ),
            documents: new DocumentsRule(
                containerSelectors: SelectorChain::fromArray(['div.blockFilesTabDocs']),
                itemSelectors: SelectorChain::fromArray(['div.attachment']),
                titleSelectors: SelectorChain::fromArray(['span.section__value > a']),
                urlSelectors: SelectorChain::fromArray(['span.section__value > a']),
                publishedAtSelectors: SelectorChain::fromArray(['span.section__info']),
            ),
            eventJournal: new EventJournalRule(
                rowSelectors: SelectorChain::fromArray(['table#event tbody tr']),
                dateSelectors: SelectorChain::fromArray(['td:nth-child(1)']),
                descriptionSelectors: SelectorChain::fromArray(['td:nth-child(2)']),
                classifications: [
                    new EventClassificationRule(
                        eventType: ProcurementEventType::StatusChanged,
                        pattern: new RegexPattern('/переведена?\s+на\s+этап[аеу]?\s*[«"]([^»"]+)[»"]/ui'),
                        priority: 100,
                    ),
                    new EventClassificationRule(
                        eventType: ProcurementEventType::Cancelled,
                        pattern: new RegexPattern('/отменен/ui'),
                        priority: 90,
                    ),
                    new EventClassificationRule(
                        eventType: ProcurementEventType::Restored,
                        pattern: new RegexPattern('/восстановлен/ui'),
                        priority: 80,
                    ),
                ],
                dateParsing: new DateParsingRule(
                    formats: ['!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y'],
                    timezone: 'Europe/Moscow',
                ),
            ),
            supplierResults: new SupplierResultsRule(
                containerSelectors: SelectorChain::fromArray(['div[id^="supplier-def-result-participant-table-"] table.tableBlock']),
                participantRowSelectors: SelectorChain::fromArray(['tbody tr']),
                winnerMarkers: ['Победитель', 'Признан победителем'],
            ),
        );
    }

    /**
     * Карта соответствия сырой метки способа определения поставщика (44-ФЗ) значению {@see EisProcedureType}.
     *
     * @return array<string, string>
     */
    public static function procedureTypeMap(): array
    {
        return [
            'электронный аукцион' => EisProcedureType::ElectronicAuction->value,
            'электронный аукцион на проведение работ по строительству, реконструкции, кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 закона № 44-фз' => EisProcedureType::ElectronicAuction->value,
            'открытый конкурс в электронной форме' => EisProcedureType::OpenCompetition->value,
            'конкурс в электронной форме' => EisProcedureType::OpenCompetition->value,
            'открытый конкурс в электронной форме на проведение работ по строительству, реконструкции, кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 закона № 44-фз' => EisProcedureType::OpenCompetition->value,
            'конкурс с ограниченным участием в электронной форме' => EisProcedureType::LimitedParticipationCompetition->value,
            'двухэтапный конкурс в электронной форме' => EisProcedureType::TwoStageCompetition->value,
            'запрос котировок в электронной форме' => EisProcedureType::QuotationRequest->value,
            'запрос котировок в электронной форме на проведение работ по строительству, реконструкции, кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 закона № 44-фз' => EisProcedureType::QuotationRequest->value,
            'запрос предложений в электронной форме' => EisProcedureType::ProposalRequest->value,
            'закупка у единственного поставщика (подрядчика, исполнителя)' => EisProcedureType::SingleSupplier->value,
            'закупка у единственного поставщика' => EisProcedureType::SingleSupplier->value,
            'закупка, осуществляемая в соответствии с частью 12 статьи 93 закона № 44-фз' => EisProcedureType::Part12Article93Purchase->value,
            'электронный аукцион на оказание услуг или выполнение работ по капитальному ремонту общего имущества в многоквартирном доме' => EisProcedureType::CapitalRepairAuction->value,
            'предварительный отбор квалифицированных подрядных организаций' => EisProcedureType::PreliminarySelection->value,
        ];
    }
}
