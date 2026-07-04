<?php

declare(strict_types=1);

test('healthcheck-эндпоинт /up отвечает успехом', function () {
    $this->get('/up')->assertSuccessful();
});
