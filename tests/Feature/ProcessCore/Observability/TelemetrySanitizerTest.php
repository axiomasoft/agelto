<?php

declare(strict_types=1);

use App\Support\Telemetry\SafeJsonLogProcessor;
use App\Support\Telemetry\SentrySanitizer;
use App\Support\Telemetry\TelemetrySanitizer;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Tracing\Span;

pest()->group('safe-telemetry');

function telemetryLogPath(): string
{
    return (string) config('logging.channels.json_file.handler_with.stream');
}

beforeEach(function (): void {
    File::delete(telemetryLogPath());
});

afterEach(function (): void {
    File::delete(telemetryLogPath());
});

test('text очищает URL userinfo/query/fragment, signed URL, Authorization/Cookie и URL-encoded пары', function (): void {
    $sanitizer = new TelemetrySanitizer;
    $text = $sanitizer->text(
        'GET https://user:pw-SECRET@host.example:8443/a/b?token=QSECRET&sig=SIGSECRET#FRAGSECRET failed; '
        .'Authorization: Bearer BEARERSECRET; Cookie: sid=COOKIESECRET; '
        .'proxy http://puser:ppass-SECRET@proxy.example:3128',
    );
    // Пары вне заголовков и вне URL, в том числе URL-encoded, и bearer без заголовка.
    $pairs = $sanitizer->text('retry password%3DENCSECRET then api_key=KEYSECRET and Bearer INLINESECRET end');

    expect($pairs)->not->toContain('ENCSECRET')->not->toContain('KEYSECRET')->not->toContain('INLINESECRET');

    expect($text)->toContain('https://host.example:8443/a/b?[redacted]')
        ->and($text)->not->toContain('SECRET')
        ->and($text)->not->toContain('user:')
        ->and($text)->not->toContain('puser');
});

test('context не пропускает секреты по ключам, сырой prompt/ответ модели/cursor/body и nested exception', function (): void {
    $safe = (new TelemetrySanitizer)->context([
        'work_id' => 'w-1',
        'owner_token' => 'OWNERSECRET',
        'Authorization' => 'Bearer AUTHSECRET',
        'prompt' => 'RAW PROMPT TEXT',
        'model_response' => 'RAW MODEL ANSWER',
        'raw_text' => 'RAW DOCUMENT BODY',
        'cursor' => 'CURSORSECRET',
        'nested' => [
            'url' => 'https://h.example/p?sig=NESTEDSECRET',
            'failure' => new RuntimeException('Cookie: sid=EXCSECRET at https://u:p-EXC@h.example/x?token=EXCTOKEN'),
        ],
    ]);
    $json = json_encode($safe, JSON_THROW_ON_ERROR);

    expect($safe['work_id'])->toBe('w-1')
        ->and($safe['owner_token'])->toBe(TelemetrySanitizer::REDACTED)
        ->and($safe['prompt'])->toBe(TelemetrySanitizer::OMITTED)
        ->and($safe['model_response'])->toBe(TelemetrySanitizer::OMITTED)
        ->and($safe['nested']['failure']['class'])->toBe(RuntimeException::class);
    foreach (['OWNERSECRET', 'AUTHSECRET', 'RAW PROMPT', 'RAW MODEL', 'RAW DOCUMENT', 'CURSORSECRET', 'NESTEDSECRET', 'EXCSECRET', 'EXC', 'EXCTOKEN'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

test('размер, глубина и число полей bounded', function (): void {
    $sanitizer = new TelemetrySanitizer;
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep']]]]]];
    $wide = array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(1, 80)), 'v');

    expect(json_encode($sanitizer->context($deep), JSON_THROW_ON_ERROR))->not->toContain('deep')
        ->and(count($sanitizer->context($wide)))->toBe(51)
        ->and(mb_strlen($sanitizer->text(str_repeat('x', 5000))))->toBeLessThan(600);
});

test('json_file канал после SafeJsonLogProcessor не содержит URL secrets, raw model text и Context URL', function (): void {
    Context::add('url', 'https://app.example/run?token=CTXSECRET');
    Context::add('trace_id', 'abc');

    Log::channel('json_file')->error('failed https://u:pw-LOGSECRET@host.example/x?token=LOGTOKEN#FRAG', [
        'prompt' => 'RAW MODEL TEXT',
        'cursor' => 'RAWCURSOR',
        'exception' => new RuntimeException('Cookie: sid=LOGCOOKIE'),
    ]);

    $contents = File::get(telemetryLogPath());
    $decoded = json_decode((string) collect(explode(PHP_EOL, trim($contents)))->last(), associative: true);

    expect($decoded['extra']['trace_id'])->toBe('abc')
        ->and($decoded['context']['prompt'])->toBe(TelemetrySanitizer::OMITTED);
    foreach (['CTXSECRET', 'LOGSECRET', 'LOGTOKEN', 'FRAG', 'RAW MODEL TEXT', 'RAWCURSOR', 'LOGCOOKIE', 'pw-'] as $leak) {
        expect($contents)->not->toContain($leak);
    }
});

test('placeholder интерполируется только после санитайзера context', function (): void {
    expect(config('logging.channels.json_file.processors'))->toBe([
        SafeJsonLogProcessor::class,
        PsrLogMessageProcessor::class,
    ])->and(config('logging.channels.json_stderr.processors'))->toBe([
        SafeJsonLogProcessor::class,
        PsrLogMessageProcessor::class,
    ]);

    Log::channel('json_file')->info('model: {prompt}', ['prompt' => 'PRIVATE_MODEL_TEXT']);

    $contents = File::get(telemetryLogPath());
    expect($contents)->not->toContain('PRIVATE_MODEL_TEXT')
        ->and($contents)->toContain(TelemetrySanitizer::OMITTED);
});

test('SafeJsonLogProcessor подключён ко всем JSON каналам и не пропускает сырую запись при сбое', function (): void {
    expect(config('logging.channels.json_file.processors'))->toContain(SafeJsonLogProcessor::class)
        ->and(config('logging.channels.json_stderr.processors'))->toContain(SafeJsonLogProcessor::class);

    $record = new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'msg token=PROCSECRET', ['resource' => fopen('php://memory', 'r')]);
    $safe = app(SafeJsonLogProcessor::class)($record);

    expect($safe->message)->not->toContain('PROCSECRET')
        ->and($safe->context['resource'])->toBe('[unsupported]');
});

test('Sentry before_send очищает message, extra, request, exception value и breadcrumbs', function (): void {
    expect(config('sentry.before_send'))->toBe([SentrySanitizer::class, 'beforeSend'])
        ->and(config('sentry.before_breadcrumb'))->toBe([SentrySanitizer::class, 'beforeBreadcrumb']);

    $event = Event::createEvent();
    $event->setMessage('failed https://u:pw-SENTSECRET@h.example/a?token=SENTTOKEN');
    $event->setExtra(['prompt' => 'RAW SENTRY PROMPT', 'work_id' => 'w-1']);
    $event->setTags(['prompt' => 'PRIVATE_TAG_TEXT', 'component' => 'fixture']);
    $event->setRequest([
        'method' => 'POST',
        'url' => 'https://h.example/doc?sig=REQSIG',
        'headers' => ['Cookie' => 'sid=REQCOOKIE'],
        'data' => 'RAW REQUEST BODY',
    ]);
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Authorization: Bearer EXCBEARER'))]);
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'http', 'GET https://h.example/b?token=CRUMBTOKEN', ['cursor' => 'CRUMBCURSOR', 'ok' => 'yes'])]);

    $safe = SentrySanitizer::beforeSend($event);

    expect($safe)->not->toBeNull();
    $json = json_encode([
        $safe->getMessage(), $safe->getExtra(), $safe->getRequest(), $safe->getTags(),
        array_map(static fn (ExceptionDataBag $bag): string => $bag->getValue(), $safe->getExceptions()),
        array_map(static fn (Breadcrumb $crumb): array => [$crumb->getMessage(), $crumb->getMetadata()], $safe->getBreadcrumbs()),
    ], JSON_THROW_ON_ERROR);
    foreach (['SENTSECRET', 'SENTTOKEN', 'RAW SENTRY', 'PRIVATE_TAG_TEXT', 'REQSIG', 'REQCOOKIE', 'RAW REQUEST', 'EXCBEARER', 'CRUMBTOKEN', 'CRUMBCURSOR'] as $leak) {
        expect($json)->not->toContain($leak);
    }
    expect($safe->getExtra()['work_id'])->toBe('w-1')
        ->and($safe->getRequest())->toBe(['method' => 'POST', 'url' => 'https://h.example/doc?[redacted]']);
});

test('transaction span теряет http.query, http.fragment и секрет в description', function (): void {
    expect(config('sentry.before_send_transaction'))->toBe([SentrySanitizer::class, 'beforeSendTransaction']);

    $span = new Span;
    $span->setData(['http.query' => 'token=SPANSECRET', 'http.fragment' => 'FRAGSECRET']);
    $span->setDescription('GET https://h.example/path?token=SPANSECRET#FRAGSECRET');
    $event = Event::createTransaction();
    $event->setSpans([$span]);

    $safe = SentrySanitizer::beforeSendTransaction($event);

    expect($safe)->not->toBeNull();
    $json = json_encode([$safe->getSpans()[0]->getData(), $safe->getSpans()[0]->getDescription()], JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('SPANSECRET')->and($json)->not->toContain('FRAGSECRET');
});

test('transaction span не сохраняет секрет за пределом допустимого числа полей', function (): void {
    $data = array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(1, 50)), 'ok');
    $data['http.query'] = 'opaque=TAILSECRET';
    $span = new Span;
    $span->setData($data);
    $span->setOp('http.client');
    $span->setTags(['component' => 'fixture', 'authorization' => 'Bearer TAGSECRET']);
    $span->setSampled(true);
    $span->finish($span->getStartTimestamp() + 1.0);
    $event = Event::createTransaction();
    $event->setSpans([$span]);

    $safe = SentrySanitizer::beforeSendTransaction($event);

    expect($safe)->not->toBeNull();
    $clean = $safe->getSpans()[0];
    expect(json_encode($clean->getData(), JSON_THROW_ON_ERROR))->not->toContain('TAILSECRET')
        ->and(array_key_exists('http.query', $clean->getData()))->toBeFalse()
        ->and(count($clean->getData()))->toBeLessThanOrEqual(51)
        ->and((string) $clean->getTraceId())->toBe((string) $span->getTraceId())
        ->and((string) $clean->getSpanId())->toBe((string) $span->getSpanId())
        ->and($clean->getStartTimestamp())->toBe($span->getStartTimestamp())
        ->and($clean->getEndTimestamp())->toBe($span->getEndTimestamp())
        ->and($clean->getOp())->toBe('http.client')
        ->and($clean->getTags())->toBe(['component' => 'fixture', 'authorization' => TelemetrySanitizer::REDACTED])
        ->and($clean->getSampled())->toBeTrue();
});

test('http.query и http.fragment подавляются даже без имени параметра', function (): void {
    $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, [
        'http.query' => 'opaque=QUERYSECRET',
        'http.fragment' => 'FRAGSECRET',
    ]);

    $safe = SentrySanitizer::beforeBreadcrumb($breadcrumb);

    expect($safe)->not->toBeNull();
    $json = json_encode($safe->getMetadata(), JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('QUERYSECRET')->and($json)->not->toContain('FRAGSECRET');
});

test('breadcrumb собирается заново и теряет секрет за границей числа полей', function (): void {
    $metadata = array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(1, 50)), 'ok');
    $metadata['password'] = 'TAILSECRET';
    $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'test', null, $metadata);

    $safe = SentrySanitizer::beforeBreadcrumb($breadcrumb);

    expect($safe)->not->toBeNull();
    $kept = $safe->getMetadata();
    expect(json_encode($kept, JSON_THROW_ON_ERROR))->not->toContain('TAILSECRET')
        ->and(array_key_exists('password', $kept))->toBeFalse()
        ->and(count($kept))->toBeLessThanOrEqual(51);
});

test('response body stays out of context and Sentry while the retired log writer is absent', function (): void {
    $exception = new RequestException(new HttpClientResponse(new Psr7Response(500, [], 'RAW_DOCUMENT_BODY')));
    $context = (new TelemetrySanitizer)->context(['exception' => $exception]);

    expect(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('RAW_DOCUMENT_BODY')
        ->and($context['exception']['message'])->toContain('500');

    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag($exception)]);
    $safe = SentrySanitizer::beforeSend($event);
    expect($safe)->not->toBeNull()
        ->and($safe->getExceptions()[0]->getValue())->not->toContain('RAW_DOCUMENT_BODY');

    expect(class_exists('App\\Services\\Procurement\\OperationLogWriter'))->toBeFalse();
});

test('AssignTraceId кладёт в Context route/origin/шаблон пути, а не raw fullUrl с query', function (): void {
    $this->get('/?token=QUERYSECRET&cursor=RAWCURSOR')->assertSuccessful();

    $url = Context::get('url');

    expect($url)->toBeString()->toStartWith('http')
        ->and($url)->not->toContain('QUERYSECRET')
        ->and($url)->not->toContain('RAWCURSOR')
        ->and($url)->not->toContain('?');
});

test('handler Context получает имя и шаблон пути после сопоставления route', function (): void {
    Route::get('/review/{id}', static function () {
        return response()->json([
            'route' => Context::get('route'),
            'url' => Context::get('url'),
        ]);
    })->name('p114.review');

    $response = $this->get('/review/42?token=QUERYSECRET');

    $response->assertSuccessful();
    expect($response->json('route'))->toBe('p114.review')
        ->and($response->json('url'))->toBeString()
        ->and($response->json('url'))->toContain('/review/{id}')
        ->and($response->json('url'))->not->toContain('QUERYSECRET')
        ->and($response->json('url'))->not->toContain('?');
});
