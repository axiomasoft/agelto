<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    // `tries: 1` во ВСЕХ supervisor'ах ниже — не недосмотр (D135, P7.7): ретрай стадий Procurement
    // сознательно вынесен из очереди в доменный контур `HasErrorBlock` (attempts/next_retry_at/
    // failed_permanently на самой модели) + расписанный `pipeline:retry-failed`. Свойств
    // очередного ретрая (`$tries`/`$backoff`/`retryUntil()`) у job'ов Procurement быть не должно —
    // сторож `tests/Unit/Architecture/JobRetryContractGuardTest.php`.
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // Раздельные очереди пайплайна по стоимости/риску (ТЗ 03 §4, приоритет ↓
        // скачивание→подготовка→скрининг→извлечение). LLM-очереди — маленький maxProcesses
        // (провайдерский rate-limit, D-конвенции); doc_prepare — CPU/IO-bound, шире.
        'supervisor-doc-prepare' => [
            'connection' => 'redis',
            'queue' => ['doc_prepare'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-ai-screening' => [
            'connection' => 'redis',
            'queue' => ['ai_screening'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // Самый длинный супервизор пайплайна — задаёт потолок инварианта D26
        // (plans/EST-AI/plan.md; research/J §2): timeout < queue.redis.retry_after
        // (config/queue.php, 360s) < docker-compose.prod.yml horizon.stop_grace_period (600s).
        // Checkpoint-requeue (D31, config/ai.php extraction.checkpoint_seconds=240s) держит
        // единичный запуск EstimateExtractionJob строго короче этого timeout.
        'supervisor-ai-extraction' => [
            'connection' => 'redis',
            'queue' => ['ai_extraction'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],

        // Д-1 (P7.7, research/10 §3): `ShadowAnalysisJob::onQueue('ai_shadow')` диспатчился в
        // очередь, которую ни один supervisor не разбирал — теневые прогоны копились бы в Redis
        // молча, пока shadow-маршруты (`llm_routes.shadow=true`) не заведены. `timeout`/`memory` —
        // потолок `supervisor-ai-extraction` (тень дублирует ЛЮБОЙ боевой вызов, включая
        // extraction, `ShadowDispatch::afterCall()` вызывается после screening И extraction);
        // `maxProcesses=1` без per-environment override — низкий приоритет, shadow-контур ещё не
        // включён ни на одном маршруте (Д-1 факт: ключа `ai_shadow` в Redis нет), масштабировать
        // раньше первого реального использования — подгонка без факта (Implementation Rule 6).
        'supervisor-ai-shadow' => [
            'connection' => 'redis',
            'queue' => ['ai_shadow'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],

        // Прогон краулера на очереди (P3.2, D27/D28). Инвариант таймаутов: checkpoint_seconds
        // (config('crawler.run.checkpoint_seconds')=240) < timeout (300) <
        // queue.redis.retry_after (config/queue.php, 360) — нарушение отдаёт job ВТОРОМУ
        // воркеру, пока первый ещё легитимно работает (площадку начнут обходить дважды).
        // `maxProcesses => 1` — сознательное ограничение: параллельные обходы одной площадки
        // есть ровно то, ради предотвращения чего существует P4 (общий бюджет запросов);
        // когда P4 введёт бюджет, значение пересматривается ЕЮ, не этим item'ом.
        'supervisor-crawl' => [
            'connection' => 'redis',
            'queue' => ['crawl'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-doc-prepare' => [
                'maxProcesses' => 6,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-ai-screening' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-ai-extraction' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
            'supervisor-doc-prepare' => [
                'maxProcesses' => 2,
            ],
            'supervisor-ai-screening' => [
                'maxProcesses' => 1,
            ],
            'supervisor-ai-extraction' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
