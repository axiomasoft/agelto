<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Crawler\StageSettings;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as F;

test('adapter filter survives profile copy while validated endpoint policy owns block size retries and required columns', function (string $key) {
    $stage = StageSettings::validateAndCreate(['retries' => 3]);
    $source = F::create();
    $target = F::create();
    $target->filter = $source->fresh()->filter;
    $target->save();
    $query = F::query($target);

    expect($target->fresh()->filter->toArray())->toBe($source->fresh()->filter->toArray())
        ->and($query['to'])->toBe('500')->and($query['registryNumberCsv'])->toBe('on')
        ->and((new EisPolicy(config()))->endpoints->get('listing')->profile->attempts)->toBe(3)
        ->and($stage->retries)->toBe(3)
        ->and(StageSettings::validateAndCreate($stage->toArray())->toArray())->toBe($stage->toArray())
        ->and(fn () => StageSettings::validateAndCreate([$key => 500]))->toThrow(ValidationException::class);
})->with(['records_per_page', 'recordsPerPage']);

test('неизвестные ключи и конфликтующие варианты имени options отклоняются', function (array $payload) {
    expect(fn () => StageSettings::validateAndCreate($payload))->toThrow(ValidationException::class);
})->with([
    [['unknown' => true]],
    [['records_per_page' => 500, 'recordsPerPage' => 20]],
]);
