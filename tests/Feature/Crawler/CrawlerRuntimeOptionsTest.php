<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\CrawlerRuntimeOptions;
use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Validation\ValidationException;

test('typed options проходят сериализацию и сохранение другого профиля без потери значений', function (string $key) {
    $options = CrawlerRuntimeOptions::validateAndCreate([$key => 500, 'columns' => ['registryNumberCsv' => true], 'retries' => 3]);
    $source = Crawler::factory()->create(['options' => $options]);
    $target = Crawler::factory()->create();
    $target->options = $source->fresh()->options;
    $target->save();

    expect($target->fresh()->options->recordsPerPage)->toBe(500)
        ->and($target->fresh()->options->retries)->toBe(3)
        ->and($target->fresh()->options->columns)->toBe(['registryNumberCsv' => true])
        ->and(CrawlerRuntimeOptions::validateAndCreate($options->toArray())->toArray())->toBe($options->toArray());
})->with(['records_per_page', 'recordsPerPage']);

test('неизвестные ключи и конфликтующие варианты имени options отклоняются', function (array $payload) {
    expect(fn () => CrawlerRuntimeOptions::validateAndCreate($payload))->toThrow(ValidationException::class);
})->with([
    [['unknown' => true]],
    [['records_per_page' => 500, 'recordsPerPage' => 20]],
]);
