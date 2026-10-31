<?php

declare(strict_types=1);

// Exact legacy file/import pairs. Remove each pair with its owning cutover; no additions.
return [
    0 => [
        'file' => 'app/Console/Commands/Procurement/SyncOkpd2Nodes.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Filters\\Okpd2NodeSynchronizer',
        'owner' => 'P2.18',
    ],
    1 => [
        'file' => 'app/Console/Commands/Procurement/VerifyDocumentUid.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\DocumentsParser',
        'owner' => 'P2.18',
    ],
    2 => [
        'file' => 'app/Console/Commands/Procurement/VerifyDocumentUid.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\EisCardUrlBuilder',
        'owner' => 'P2.18',
    ],
    3 => [
        'file' => 'app/Console/Commands/Procurement/VerifyFreshBoundary.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisListingQueryOverrides',
        'owner' => 'P2.18',
    ],
    4 => [
        'file' => 'app/Console/Commands/Procurement/VerifyFreshBoundary.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Csv\\CsvSourceMethod',
        'owner' => 'P2.18',
    ],
    5 => [
        'file' => 'app/Filament/Platforms/Eis/EisCrawlerPlatformForm.php',
        'import' => 'App\\Services\\Procurement\\CrawlerFilterFactory',
        'owner' => 'P2.18',
    ],
    6 => [
        'file' => 'app/Repositories/Procurement/ProcurementDetailStoreRepository.php',
        'import' => 'App\\Models\\Platforms\\Eis\\EisDetails',
        'owner' => 'P2.18',
    ],
    7 => [
        'file' => 'app/Services/Platforms/Eis/Card/DocumentsParser.php',
        'import' => 'App\\Services\\Procurement\\SourceTargetValidator',
        'owner' => 'P2.6',
    ],
    8 => [
        'file' => 'app/Services/Platforms/Eis/Card/EisCardUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    9 => [
        'file' => 'app/Services/Platforms/Eis/Card/EisCardUrlBuilder.php',
        'import' => 'App\\Services\\Procurement\\Exceptions\\CardUrlTemplateMissingException',
        'owner' => 'P2.6',
    ],
    10 => [
        'file' => 'app/Services/Platforms/Eis/Card/EisDetailParser.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\DetailParser',
        'owner' => 'P2.18',
    ],
    11 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Actions\\Procurement\\Crawler\\StoreRawFetchArtifactAction',
        'owner' => 'P2.6',
    ],
    12 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    13 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Platform',
        'owner' => 'P2.18',
    ],
    14 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    15 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Procurement',
        'owner' => 'P2.18',
    ],
    16 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\CooperativeListingSourceMethod',
        'owner' => 'P2.18',
    ],
    17 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\DeadlineAwareSourceMethod',
        'owner' => 'P2.18',
    ],
    18 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\PlatformAdapterRegistry',
        'owner' => 'P2.6',
    ],
    19 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    20 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\ListingRowMapper',
        'owner' => 'P2.18',
    ],
    21 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\ListingSchemaAware',
        'owner' => 'P2.18',
    ],
    22 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    23 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    24 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Repositories\\Procurement\\Platform\\Okpd2NodeReadRepository',
        'owner' => 'P2.6',
    ],
    25 => [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    26 => [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Platform',
        'owner' => 'P2.18',
    ],
    27 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    28 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\CrawlSettingsResolver',
        'owner' => 'P2.6',
    ],
    29 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\SourceTlsTrust',
        'owner' => 'P2.6',
    ],
    30 => [
        'file' => 'app/Services/Procurement/Detail/CommonInfoProcessor.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\CommonInfoParser',
        'owner' => 'P2.18',
    ],
    31 => [
        'file' => 'app/Services/Procurement/Health/PlatformHealthChecker.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Diagnostics\\ListingFilterCheck',
        'owner' => 'P2.18',
    ],
    32 => [
        'file' => 'database/factories/Procurement/Crawler/CrawlerFactory.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisSearchFilter',
        'owner' => 'P2.18',
    ],
    33 => [
        'file' => 'database/seeders/Procurement/CrawlerProfileSeeder.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisSearchFilter',
        'owner' => 'P2.18',
    ],
];
