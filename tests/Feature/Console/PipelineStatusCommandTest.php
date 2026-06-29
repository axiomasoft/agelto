<?php

declare(strict_types=1);

use App\Models\Crawler\Link;
use App\Models\DocumentAnalysis\DocumentAnalysis;

test('pipeline:status выводит обе таблицы стадий без ошибок', function () {
    Link::factory()->pending()->create();
    DocumentAnalysis::factory()->completed()->create();

    $this->artisan('app:pipeline:status')->assertSuccessful();
});
