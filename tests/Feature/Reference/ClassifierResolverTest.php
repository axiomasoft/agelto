<?php

declare(strict_types=1);

use App\Models\Reference\Kbk;
use App\Models\Reference\Ktru;
use App\Models\Reference\Okpd2;
use App\Models\Reference\Okved2;
use App\Services\Reference\ClassifierResolver;

test('resolveOkpd2 возвращает id существующего кода', function () {
    $okpd2 = Okpd2::factory()->create(['code' => '62.01.11']);

    $id = app(ClassifierResolver::class)->resolveOkpd2(code: '62.01.11');

    expect($id)->toBe($okpd2->id);
});

test('resolveOkpd2 возвращает null для отсутствующего кода', function () {
    $id = app(ClassifierResolver::class)->resolveOkpd2(code: '99.99.99');

    expect($id)->toBeNull();
});

test('resolveOkpd2 возвращает null без падения для пустого/отсутствующего кода', function () {
    $resolver = app(ClassifierResolver::class);

    expect($resolver->resolveOkpd2(code: null))->toBeNull()
        ->and($resolver->resolveOkpd2(code: ''))->toBeNull();
});

test('resolveKtru/resolveOkved2/resolveKbk резолвят по точному code', function () {
    $ktru = Ktru::factory()->create(['code' => '62.01.11-00000001']);
    $okved2 = Okved2::factory()->create(['code' => '62.01']);
    $kbk = Kbk::factory()->create(['code' => '000 0000 00000 00 0000 000']);

    $resolver = app(ClassifierResolver::class);

    expect($resolver->resolveKtru(code: $ktru->code))->toBe($ktru->id)
        ->and($resolver->resolveOkved2(code: $okved2->code))->toBe($okved2->id)
        ->and($resolver->resolveKbk(code: $kbk->code))->toBe($kbk->id);
});
