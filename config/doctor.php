<?php

use Laravel\Doctor\Diagnostics\BootstrapCacheMatchesEnvironment;
use Laravel\Doctor\Diagnostics\ComposerAuditPasses;
use Laravel\Doctor\Diagnostics\ComposerAutoloadIsValid;
use Laravel\Doctor\Diagnostics\EnvironmentFileExists;
use Laravel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored;

return [

    /*
    |--------------------------------------------------------------------------
    | Diagnostic Selection
    |--------------------------------------------------------------------------
    |
    | These selectors use the same syntax as the --only and --except command
    | options: diagnostic class names, groups, Composer packages, or package
    | wildcards. Leave them empty to run every registered diagnostic.
    |
    */

    'only' => [
        // 'laravel/doctor',
        // 'vendor/*',
        // 'security',
    ],

    'except' => [
        // Production receives config from Compose and file-backed secrets. Its immutable
        // image deliberately has neither a `.env` file nor a Composer write workflow.
        EnvironmentFileExists::class,
        EnvironmentFileIsGitIgnored::class,
        ComposerAutoloadIsValid::class,
        ComposerAuditPasses::class,
        // Config is resolved after the entrypoint imports file-backed secrets.  Baking a
        // config cache into a portable immutable image would freeze a different host's values.
        BootstrapCacheMatchesEnvironment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Modes
    |--------------------------------------------------------------------------
    |
    | Group Laravel environment names by the Doctor mode whose expectations
    | should apply. Any environment not listed here is treated as
    | production, the strictest mode.
    |
    */

    'environments' => [
        'local' => ['local'],
        'production' => ['production', 'staging'],
    ],

];
