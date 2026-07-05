<?php

declare(strict_types=1);

test('has welcome page', function (): void {
    $page = visit('/');

    $page->assertSee('Laravel');
});
