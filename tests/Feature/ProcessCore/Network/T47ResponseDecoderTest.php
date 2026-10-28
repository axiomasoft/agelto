<?php

declare(strict_types=1);

use App\Exceptions\Procurement\Network\ResponseDecodeFailed;
use App\Exceptions\Procurement\Network\ResponseLimitExceeded;
use App\Services\Procurement\Network\ResponseDecoder;
use Tests\Support\ProcessCore\Http\TransportFixtures;

test('T47 BOM header meta и endpoint default дают одинаковый текст с проверкой исходных bytes', function (): void {
    $decoder = new ResponseDecoder;
    $profile = TransportFixtures::profile(['charset_default' => 'Windows-1251']);
    $text = 'Привет';
    foreach ([
        [mb_convert_encoding($text, 'Windows-1251', 'UTF-8'), null],
        ["\xEF\xBB\xBF".$text, 'text/plain; charset=Windows-1251'],
        [$text, 'text/plain; charset=UTF-8'],
        ["\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), null],
    ] as [$bytes, $header]) {
        expect($decoder->decode($bytes, $header, $profile)->utf8)->toBe($text);
    }
    $html = '<meta http-equiv="Content-Type" content="text/html; charset=Windows-1251"><p>'.$text.'</p>';
    expect($decoder->decode(mb_convert_encoding($html, 'Windows-1251', 'UTF-8'), 'text/html', $profile)->utf8)->toBe($html);
    expect($decoder->decode('<meta charset="utf-8">'.$text, 'text/html', $profile)->utf8)->toBe('<meta charset="utf-8">'.$text);
})->group('t47', 'p21', 'p22');

test('T47 invalid declared UTF8 unsupported charset и UTF8 expansion отвергаются без substitution', function (): void {
    $decoder = new ResponseDecoder;
    $profile = TransportFixtures::profile();
    foreach ([['Привет', 'text/plain; charset=imaginary'], ["\xFF", 'text/plain; charset=UTF-8'], ['YWJj', 'text/plain; charset=BASE64']] as [$bytes, $header]) {
        expect(fn () => $decoder->decode($bytes, $header, $profile))->toThrow(ResponseDecodeFailed::class);
    }
    $small = TransportFixtures::profile(['max_entity_bytes' => 10, 'max_text_bytes' => 10, 'charset_default' => 'Windows-1251']);
    expect(fn () => $decoder->decode(str_repeat("\xCF", 6), null, $small))->toThrow(ResponseLimitExceeded::class);
    // Meta outside the 4 KiB prefix must not override the endpoint default.
    expect(fn () => $decoder->decode(str_repeat(' ', 4096).'<meta charset="Windows-1251">'."\xCF", 'text/html', $profile))->toThrow(ResponseDecodeFailed::class);
})->group('t47', 'p21', 'p22');

test('T47 meta в comment script и data-charset не меняет declared default', function (): void {
    $decoder = new ResponseDecoder;
    $bytes = '<!-- <meta charset="Windows-1251"> --><script>"<meta charset=Windows-1251>"</script><meta data-charset="Windows-1251">Привет';
    expect($decoder->decode($bytes, 'text/html', TransportFixtures::profile())->utf8)->toBe($bytes);
})->group('t47', 'p21', 'p22');

test('T47 bounded entity и UTF8 expansion имеют измеримый memory ceiling', function (): void {
    $bytes = str_repeat("\xCF", 4 * 1024 * 1024);
    $profile = TransportFixtures::profile(['max_entity_bytes' => 4 * 1024 * 1024, 'max_text_bytes' => 12 * 1024 * 1024, 'charset_default' => 'Windows-1251']);
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $body = (new ResponseDecoder)->decode($bytes, null, $profile);
    expect(strlen($body->utf8))->toBe(8 * 1024 * 1024)
        ->and(memory_get_peak_usage(true) - $baseline)->toBeLessThan(48 * 1024 * 1024);
})->group('t47', 'p21', 'p22');
