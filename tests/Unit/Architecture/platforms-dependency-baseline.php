<?php

declare(strict_types=1);

// Exact legacy file/import pairs. Remove each pair with its owning cutover; no additions.
return [
    [
        'file' => 'app/Console/Commands/Procurement/SyncOkpd2Nodes.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Filters\\Okpd2NodeSynchronizer',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Console/Commands/Procurement/VerifyDocumentUid.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\DocumentsParser',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Console/Commands/Procurement/VerifyDocumentUid.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Card\\EisCardUrlBuilder',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Console/Commands/Procurement/VerifyFreshBoundary.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisListingQueryOverrides',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Console/Commands/Procurement/VerifyFreshBoundary.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Csv\\CsvSourceMethod',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Filament/Platforms/Eis/EisCrawlerPlatformForm.php',
        'import' => 'App\\Services\\Procurement\\CrawlerFilterFactory',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Card/DocumentsParser.php',
        'import' => 'App\\Services\\Procurement\\SourceTargetValidator',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Card/EisCardUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Card/EisCardUrlBuilder.php',
        'import' => 'App\\Services\\Procurement\\Exceptions\\CardUrlTemplateMissingException',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Card/EisDetailParser.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\DetailParser',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Actions\\Procurement\\Crawler\\StoreRawFetchArtifactAction',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Platform',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Models\\Procurement\\Procurement',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\CooperativeListingSourceMethod',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\DeadlineAwareSourceMethod',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/CsvSourceMethod.php',
        'import' => 'App\\Services\\Procurement\\PlatformAdapterRegistry',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\ListingRowMapper',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvRowMapper.php',
        'import' => 'App\\Services\\Procurement\\Contracts\\ListingSchemaAware',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Registry',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Csv/EisCsvUrlBuilder.php',
        'import' => 'App\\Repositories\\Procurement\\Platform\\Okpd2NodeReadRepository',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php',
        'import' => 'App\\Models\\Procurement\\Platform\\Platform',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Models\\Procurement\\Crawler\\Crawler',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\CrawlSettingsResolver',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php',
        'import' => 'App\\Services\\Procurement\\SourceTlsTrust',
        'owner' => 'P2.6',
    ],
    [
        'file' => 'app/Services/Procurement/Health/PlatformHealthChecker.php',
        'import' => 'App\\Services\\Platforms\\Eis\\Diagnostics\\ListingFilterCheck',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'database/factories/Procurement/Crawler/CrawlerFactory.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisSearchFilter',
        'owner' => 'P2.18',
    ],
    [
        'file' => 'database/seeders/Procurement/CrawlerProfileSeeder.php',
        'import' => 'App\\Dto\\Platforms\\Eis\\EisSearchFilter',
        'owner' => 'P2.18',
    ],
];
