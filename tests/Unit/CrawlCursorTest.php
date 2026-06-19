<?php

declare(strict_types=1);

use App\Process\Crawling\Cursor\CrawlCursor;

test('push/pop интервалов работает как стек (LIFO)', function () {
    $cursor = new CrawlCursor();
    expect($cursor->hasIntervals())->toBeFalse();

    $cursor->pushInterval(0, 500);
    $cursor->pushInterval(500.01, 1000);

    expect($cursor->hasIntervals())->toBeTrue()
        ->and($cursor->popInterval())->toBe(['from' => 500.01, 'to' => 1000.0])
        ->and($cursor->popInterval())->toBe(['from' => 0.0, 'to' => 500.0])
        ->and($cursor->popInterval())->toBeNull()
        ->and($cursor->hasIntervals())->toBeFalse();
});

test('toState/fromState round-trip сохраняет день, стек и страницу', function () {
    $cursor = new CrawlCursor(day: '2026-06-10', page: 7);
    $cursor->pushInterval(0, 1000);
    $cursor->pushInterval(1000.01, 2000);

    $restored = CrawlCursor::fromState($cursor->toState());

    expect($restored->day)->toBe('2026-06-10')
        ->and($restored->page)->toBe(7)
        ->and($restored->popInterval())->toBe(['from' => 1000.01, 'to' => 2000.0])
        ->and($restored->popInterval())->toBe(['from' => 0.0, 'to' => 1000.0]);
});

test('fromState с null/пустым состоянием даёт безопасные дефолты', function () {
    $empty = CrawlCursor::fromState(null);

    expect($empty->day)->toBeNull()
        ->and($empty->page)->toBe(1)
        ->and($empty->hasIntervals())->toBeFalse();

    // page никогда не меньше 1
    expect(CrawlCursor::fromState(['page' => 0])->page)->toBe(1);
});

test('nextPage/resetPage/setDay меняют курсор', function () {
    $cursor = new CrawlCursor(page: 1);
    $cursor->nextPage();
    $cursor->nextPage();
    expect($cursor->page)->toBe(3);

    $cursor->resetPage();
    expect($cursor->page)->toBe(1);

    $cursor->setDay('2026-06-13');
    expect($cursor->day)->toBe('2026-06-13');
});
