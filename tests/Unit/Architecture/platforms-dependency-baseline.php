<?php

declare(strict_types=1);

// Exact legacy file/import pairs. Remove each pair with its owning cutover; no additions.
return [
    0 => [
        'file' => 'app/Console/Commands/Procurement/SyncOkpd2Nodes.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Filters\\Okpd2NodeSynchronizer',
        'owner' => 'P2.12',
    ],
    1 => [
        'file' => 'app/Console/Commands/Procurement/VerifyDocumentUid.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\DocumentsParser',
        'owner' => 'P2.12',
    ],
    2 => [
        'file' => 'app/Console/Commands/Procurement/VerifyDocumentUid.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\EisCardUrlBuilder',
        'owner' => 'P2.12',
    ],
    3 => [
        'file' => 'app/Console/Commands/Procurement/VerifyFreshBoundary.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisListingQueryOverrides',
        'owner' => 'P2.12',
    ],
    4 => [
        'file' => 'app/Console/Commands/Procurement/VerifyFreshBoundary.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Csv\\CsvSourceMethod',
        'owner' => 'P2.12',
    ],
    5 => [
        'file' => 'app/Filament/Platforms/Eis/EisCrawlerPlatformForm.php',
        'import' => 'App\\Services\\Procurement\\CrawlerFilterFactory',
        'owner' => 'P2.12',
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
        'owner' => 'P2.12',
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
        'import' => 'App\\Services\\Procurement\\BoundedResponseReader',
        'owner' => 'P2.6',
    ],
    17 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\CooperativeListingSourceMethod',
        'owner' => 'P2.12',
    ],
    18 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\DeadlineAwareSourceMethod',
        'owner' => 'P2.12',
    ],
    19 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\CrawlSettingsResolver',
        'owner' => 'P2.6',
    ],
    20 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Exceptions\\CardPageMismatchException',
        'owner' => 'P2.6',
    ],
    21 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\FetchLogger',
        'owner' => 'P2.6',
    ],
    22 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\PlatformAdapterRegistry',
        'owner' => 'P2.6',
    ],
    23 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\PlatformRequestBudget',
        'owner' => 'P2.6',
    ],
    24 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Proxy\\ProxyBindingResolver',
        'owner' => 'P2.6',
    ],
    25 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Proxy\\ProxyResolver',
        'owner' => 'P2.6',
    ],
    26 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\SourceTlsTrust',
        'owner' => 'P2.6',
    ],
    27 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'GuzzleHttp\\Exception\\ConnectException',
        'owner' => 'P2.6',
    ],
    28 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'GuzzleHttp\\Psr7\\Utils',
        'owner' => 'P2.6',
    ],
    29 => [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'Illuminate\\Support\\Facades\\Http',
        'owner' => 'P2.6',
    ],
    30 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvParser.php',
        'import' => 'App\\Services\\Procurement\\BoundedResponseReader',
        'owner' => 'P2.6',
    ],
    31 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    32 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\ListingRowMapper',
        'owner' => 'P2.12',
    ],
    33 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\ListingSchemaAware',
        'owner' => 'P2.12',
    ],
    34 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    35 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    36 => [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Repositories\\Procurement\\Platform\\Okpd2NodeReadRepository',
        'owner' => 'P2.6',
    ],
    37 => [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    38 => [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Platform',
        'owner' => 'P2.18',
    ],
    39 => [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Services\\Procurement\\Health\\Contracts\\HealthCheck',
        'owner' => 'P2.12',
    ],
    40 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    41 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\CrawlSettingsResolver',
        'owner' => 'P2.6',
    ],
    42 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\PlatformRequestBudget',
        'owner' => 'P2.6',
    ],
    43 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\Proxy\\ProxyResolver',
        'owner' => 'P2.6',
    ],
    44 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\SourceTlsTrust',
        'owner' => 'P2.6',
    ],
    45 => [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'Illuminate\\Support\\Facades\\Http',
        'owner' => 'P2.6',
    ],
    46 => [
        'file' => 'app/Services/Procurement/Detail/CommonInfoProcessor.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\CommonInfoParser',
        'owner' => 'P2.18',
    ],
    47 => [
        'file' => 'app/Services/Procurement/Health/PlatformHealthChecker.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Diagnostics\\ListingFilterCheck',
        'owner' => 'P2.18',
    ],
    48 => [
        'file' => 'database/factories/Procurement/Crawler/CrawlerFactory.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisSearchFilter',
        'owner' => 'P2.12',
    ],
    49 => [
        'file' => 'database/seeders/Procurement/CrawlerProfileSeeder.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisSearchFilter',
        'owner' => 'P2.18',
    ],
];
