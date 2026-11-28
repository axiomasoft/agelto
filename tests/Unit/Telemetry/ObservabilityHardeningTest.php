<?php

declare(strict_types=1);

use App\Enums\Health\ReadinessCheckCode;
use App\Services\Health\ApplicationReadiness;
use App\Services\Health\PgTimeoutGuard;
use App\Support\Telemetry\SentrySanitizer;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Security\SecretRedactor;
use Sentry\Logs\Log as SentryLog;
use Sentry\Logs\LogLevel;
use Sentry\Options;
use Symfony\Component\Process\Process;

test('Sentry очищает сообщение, ключи и значения атрибутов лога', function (): void {
    $log = (new SentryLog(timestamp: 123.5, traceId: str_repeat('a', 32), level: LogLevel::warn(), body: 'token=body-secret'))
        ->setAttribute(key: 'authorization', value: 'attribute-secret')
        ->setAttribute(key: 'token=key-secret', value: 'attribute-value')
        ->setAttribute(key: 'count', value: 3);
    for ($index = 0; $index < 50; $index++) {
        $log->setAttribute(key: "field{$index}", value: 'value');
    }
    $log->setAttribute(key: 'excess', value: 'excess-secret');

    expect(config('sentry.before_send_log'))->toBe([SentrySanitizer::class, 'beforeSendLog']);
    $options = new Options(['before_send_log' => config('sentry.before_send_log')]);
    $safe = ($options->getBeforeSendLogCallback())($log);

    expect($safe)->toBeInstanceOf(SentryLog::class)
        ->and($safe->getBody())->toBe('token=[redacted]')
        ->and($safe->getTimestamp())->toBe(123.5)
        ->and($safe->getTraceId())->toBe($log->getTraceId())
        ->and($safe->getLevel())->toBe(LogLevel::warn())
        ->and($safe->attributes()->toSimpleArray())->toMatchArray([
            'authorization' => '[redacted]',
            'token=[redacted]' => '[redacted]',
            'count' => 3,
        ])
        ->and($safe->attributes()->get('excess'))->toBeNull();
});

test('аварийный лог скрывает секреты при отказе настроенного канала', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'agelto-emergency-');
    expect($path)->toBeString();

    try {
        config(['logging.channels.emergency.path' => $path]);
        $logger = Log::channel('missing-test-channel');
        $logger->error('probe token=message-secret', ['password' => 'context-secret']);

        expect(file_get_contents($path))
            ->toContain('Using emergency logger', 'probe token=[redacted]', '[redacted]')
            ->not->toContain('message-secret', 'context-secret');
    } finally {
        unlink($path);
    }
});

test('проверка готовности восстанавливает таймауты внешней транзакции', function (): void {
    DB::connection()->transaction(function (Connection $connection): void {
        $connection->statement("SET LOCAL lock_timeout = '2s'");
        $connection->statement("SET LOCAL statement_timeout = '3s'");

        $check = app(ApplicationReadiness::class)->check(only: [ReadinessCheckCode::Database]);

        expect($check[0]->passed)->toBeTrue()
            ->and($connection->scalar("SELECT current_setting('lock_timeout')"))->toBe('2s')
            ->and($connection->scalar("SELECT current_setting('statement_timeout')"))->toBe('3s');
    });
});

test('чтение роли ограничивает время SQL без внешней транзакции и восстанавливает соединение после отмены', function (): void {
    $connection = DB::connection();
    $original = $connection->scalar('SHOW statement_timeout');

    expect(fn () => app(PgTimeoutGuard::class)->runAuthorityRead(hrtime(true) + 250_000_000, function () use ($connection): void {
        expect($connection->transactionLevel())->toBe(0);
        $connection->select('SELECT pg_sleep(2)');
    }))->toThrow(QueryException::class, 'statement timeout');

    expect($connection->scalar('SHOW statement_timeout'))->toBe($original);
});

test('ошибка запроса проверки готовности сохраняет внешнюю транзакцию и её таймауты', function (): void {
    DB::connection()->transaction(function (Connection $connection): void {
        $connection->statement("SET LOCAL lock_timeout = '2s'");
        $connection->statement("SET LOCAL statement_timeout = '3s'");
        $pgGuard = app(PgTimeoutGuard::class);

        expect(fn () => $pgGuard->runWithPgTimeouts(hrtime(true) + 1_000_000_000, function (Connection $probeConnection): void {
            $probeConnection->select('SELECT 1 / 0');
        }))->toThrow(QueryException::class);

        expect($connection->scalar("SELECT current_setting('lock_timeout')"))->toBe('2s')
            ->and($connection->scalar("SELECT current_setting('statement_timeout')"))->toBe('3s');
    });
});

test('резолвер отклоняет ответ DNS с непубличным адресом прокси', function (string $privateIp): void {
    $dns = new DnsResolver(scope: app(ConnectionScope::class), fixture: static fn (): array => ['8.8.8.8', $privateIp]);
    $endpoint = new Endpoint(Uuid::random(), new TenantKey('test'), ProxyProtocol::Http, 'proxy.test', 8080);

    expect(fn () => (new ProxyResolver($dns))->validatedAddress(endpoint: $endpoint, timeoutMs: 500))
        ->toThrow(RuntimeException::class, 'Proxy address denied.');
})->with(['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '::1', '::ffff:127.0.0.1', 'fc00::1']);

test('разрешение непубличного адреса прокси действует только для настроенного хоста и IP', function (): void {
    config(['proxyma.private_proxies' => ['proxy.test' => ['127.0.0.1'], 'other.test' => ['127.0.0.2']]]);
    $dns = new DnsResolver(scope: app(ConnectionScope::class), fixture: static fn (string $host): array => match ($host) {
        'public.test' => ['8.8.8.8'],
        default => ['127.0.0.1'],
    });
    app()->instance(DnsResolver::class, $dns);
    $resolver = app(ProxyResolver::class);
    $endpoint = static fn (string $host): Endpoint => new Endpoint(Uuid::random(), new TenantKey('test'), ProxyProtocol::Http, $host, 8080);

    expect($resolver->validatedAddress(endpoint: $endpoint('proxy.test'), timeoutMs: 500))->toBe('127.0.0.1')
        ->and($resolver->validatedAddress(endpoint: $endpoint('public.test'), timeoutMs: 500))->toBe('8.8.8.8');
    expect(fn () => $resolver->validatedAddress(endpoint: $endpoint('proxied.test'), timeoutMs: 500))
        ->toThrow(RuntimeException::class, 'Proxy address denied.');
    expect(fn () => $resolver->validatedAddress(endpoint: $endpoint('other.test'), timeoutMs: 500))
        ->toThrow(RuntimeException::class, 'Proxy address denied.');
});

test('подпроцесс резолвера получает имя хоста после разделителя аргументов', function (): void {
    $path = (new ReflectionClass(DnsResolver::class))->getFileName();
    expect($path)->toBeString();
    expect(file_get_contents($path))->toContain("'-d', 'display_errors=0', '-r', \$script, '--', \$host");

    $addresses = app(DnsResolver::class)->resolve(host: 'fixture-http', timeoutMs: 1000);
    expect($addresses)->not->toBeEmpty();
});

test('Proxyma скрывает токен Telegram в URL, сообщениях и ключах диагностики', function (): void {
    $redactor = new SecretRedactor;
    $value = $redactor->context([
        '/bot123:abc123' => 'https://api.telegram.org/bot123:url-secret/sendMessage',
        'message' => 'Запрос к /bot456:message-secret/getMe',
    ]);

    expect($value)->toBe([
        '/bot[redacted]' => 'https://api.telegram.org/bot[redacted]/sendMessage',
        'message' => 'Запрос к /bot[redacted]/getMe',
    ]);
});

test('локальные исправления Proxyma отклоняют неизвестную исходную версию класса', function (): void {
    $script = <<<'PHP'
$root = $argv[1];
require $root.'/vendor/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader($root.'/vendor');
$loader->addPsr4('Proxyma\\', $root.'/vendor/axiomasoft/proxyma/src');
$loader->addClassMap([
    Proxyma\Application\Execution\ProxyResolver::class => $root.'/bootstrap/proxyma/ProxyResolver.php',
]);
$loader->register();
try {
    require $root.'/bootstrap/proxyma.php';
} catch (LogicException $failure) {
    fwrite(STDERR, $failure->getMessage());
    exit(1);
}
PHP;
    $process = new Process([PHP_BINARY, '-r', $script, '--', base_path()]);

    expect($process->run())->toBe(1)
        ->and($process->getErrorOutput())->toBe('The installed Proxyma source differs from the expected source for '.ProxyResolver::class.'.');
});
