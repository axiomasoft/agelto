<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;

test('AssignTraceId добавляет trace_id и url в Context на каждый HTTP-запрос', function () {
    $this->get('/')->assertSuccessful();

    expect(Context::get('trace_id'))->toBeString()->not->toBeEmpty()
        ->and(Context::get('url'))->toBeString()->not->toBeEmpty();
});

test('trace_id уникален на каждый запрос', function () {
    $this->get('/');
    $first = Context::get('trace_id');

    $this->get('/');
    $second = Context::get('trace_id');

    expect($first)->not->toBe($second);
});
