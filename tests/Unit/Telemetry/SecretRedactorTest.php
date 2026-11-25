<?php

declare(strict_types=1);

use App\Support\SecretRedactor;
use App\Support\Telemetry\TelemetrySanitizer;

test('SecretRedactor маскирует токен Telegram-бота в URL, относительных путях и вложенном контексте', function (): void {
    $raw = 'POST https://api.telegram.org/bot123456:AAH_secret-token_01/sendMessage и путь /bot987654:BBG_other-Secret99/getMe';
    $redacted = SecretRedactor::text($raw);

    expect($redacted)->toBe('POST https://api.telegram.org/bot[redacted]/sendMessage и путь /bot[redacted]/getMe')
        ->and($redacted)->not->toContain('123456', 'AAH_secret-token_01', '987654', 'BBG_other-Secret99');

    $context = SecretRedactor::context([
        'endpoint' => 'https://api.telegram.org/bot123456:AAH_secret/sendMessage',
        'nested' => [
            'path' => '/bot123456:AAH_secret/sendMessage',
        ],
    ]);

    expect($context)->toBe([
        'endpoint' => 'https://api.telegram.org/bot[redacted]/sendMessage',
        'nested' => [
            'path' => '/bot[redacted]/sendMessage',
        ],
    ]);
});

test('TelemetrySanitizer маскирует токен Telegram-бота в путях URL, тексте и сообщениях исключений', function (): void {
    $sanitizer = new TelemetrySanitizer;

    expect($sanitizer->url('https://api.telegram.org/bot123456:AAH_secret/sendMessage?chat_id=42'))
        ->toBe('https://api.telegram.org/bot[redacted]/sendMessage?[redacted]');

    $text = $sanitizer->text('Ошибка отправки на https://api.telegram.org/bot123456:AAH_secret/sendMessage и /bot654321:CCG-token_1/sendMessage');
    expect($text)->toContain('https://api.telegram.org/bot[redacted]/sendMessage')
        ->and($text)->toContain('/bot[redacted]/sendMessage')
        ->and($text)->not->toContain('AAH_secret', 'CCG-token_1', '123456', '654321');

    $context = $sanitizer->context([
        'exception' => new RuntimeException('HTTP 400 from https://api.telegram.org/bot123456:AAH_secret/sendMessage'),
    ]);
    $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($encoded)->toContain('https://api.telegram.org/bot[redacted]/sendMessage')
        ->and($encoded)->not->toContain('AAH_secret', '123456');
});
