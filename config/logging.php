<?php

declare(strict_types=1);

use App\Support\Telemetry\SafeJsonLogProcessor;
use App\Support\Telemetry\SafeLogChannelTap;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'tap' => [SafeLogChannelTap::class],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        /*
        |----------------------------------------------------------------------
        | JSON-каналы (Monolog + JsonFormatter)
        |----------------------------------------------------------------------
        |
        | 'json' — стек file+stderr в JSON (Context::add()-поля вроде trace_id едут в extra
        | автоматически через Laravel's ContextProcessor, без правки формата вручную).
        | Не default-канал (LOG_CHANNEL не тронут) — включается явно `LOG_CHANNEL=json`
        | (прод-Docker: stdout/stderr — 12-factor) без изменения текущего dev-поведения.
        |
        | SafeJsonLogProcessor идёт первым: PsrLogMessageProcessor интерполирует {placeholder}
        | уже из очищенного context. Обратный порядок копирует сырой prompt в message.
        */

        'json' => [
            'driver' => 'stack',
            'channels' => ['json_file', 'json_stderr'],
            'ignore_exceptions' => false,
        ],

        'json_file' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/laravel-json.log'),
            ],
            'formatter' => JsonFormatter::class,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'json_stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => JsonFormatter::class,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
        ],

        /*
        |----------------------------------------------------------------------
        | Доменные и ранжированные системные каналы
        |----------------------------------------------------------------------
        |
        | Базовый канал ('single' / 'daily' -> laravel.log) принимает только обычные
        | прикладные события. Специализированные подсистемы пишут в изолированные файлы,
        | а деградации (warning) и инциденты (error..emergency) дополнительно агрегируются
        | в ранжированные каналы 'system_warnings' и 'system_errors'.
        */

        'process' => [
            'driver' => 'daily',
            'path' => storage_path('logs/process.log'),
            'level' => env('LOG_PROCESS_LEVEL', env('LOG_LEVEL', 'info')),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'network' => [
            'driver' => 'daily',
            'path' => storage_path('logs/network.log'),
            'level' => env('LOG_NETWORK_LEVEL', env('LOG_LEVEL', 'info')),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'platforms' => [
            'driver' => 'daily',
            'path' => storage_path('logs/platforms.log'),
            'level' => env('LOG_PLATFORMS_LEVEL', env('LOG_LEVEL', 'warning')),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'procurement' => [
            'driver' => 'daily',
            'path' => storage_path('logs/procurement.log'),
            'level' => env('LOG_PROCUREMENT_LEVEL', env('LOG_LEVEL', 'info')),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'storage' => [
            'driver' => 'daily',
            'path' => storage_path('logs/storage.log'),
            'level' => env('LOG_STORAGE_LEVEL', env('LOG_LEVEL', 'warning')),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'alerts' => [
            'driver' => 'daily',
            'path' => storage_path('logs/alerts.log'),
            'level' => env('LOG_ALERTS_LEVEL', env('LOG_LEVEL', 'warning')),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'system_warnings' => [
            'driver' => 'daily',
            'path' => storage_path('logs/system-warnings.log'),
            'level' => 'warning',
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'system_errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/system-errors.log'),
            'level' => 'error',
            'days' => env('LOG_DAILY_DAYS', 30),
            'replace_placeholders' => true,
            'tap' => [SafeLogChannelTap::class],
            'processors' => [SafeJsonLogProcessor::class, PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
