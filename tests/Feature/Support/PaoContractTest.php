<?php

declare(strict_types=1);

test('PAO sabotage формирует failure result только по явному contract marker', function () {
    if (getenv('P11_PAO_SABOTAGE') === '1') {
        expect(true)->toBeFalse('P11 PAO sabotage');
    }

    expect(true)->toBeTrue();
});
