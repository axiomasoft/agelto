<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Models\Procurement\Platform\Platform;
use Faker\Factory;
use Faker\Generator;

pest()->group('platform-definition-boot');

test('platform factory codes always satisfy the source catalog grammar and length bound', function (): void {
    $original = fake();
    $key = Generator::class.':'.config('app.faker_locale');
    $faker = Factory::create(config('app.faker_locale'));
    // A lawful three-word Faker slug can exceed the catalog's 32-character bound.
    $faker->addProvider(new class
    {
        public function slug($nbWords = 6, $variableNbWords = true): string
        {
            return 'reprehenderit-exercitationem-necessitatibus';
        }
    });
    app()->instance($key, $faker);
    try {
        expect(strlen(fake()->slug(2)))->toBeGreaterThan(32);
        $codes = [];
        for ($i = 0; $i < 250; $i++) {
            $code = Platform::factory()->make()->code;
            expect($code)->toMatch('/^[a-z][a-z0-9_]{0,31}$/D');
            expect((new SourceTargetDefinitions($code, []))->platformCode)->toBe($code);
            $codes[] = $code;
        }
        expect(array_unique($codes))->toHaveCount(250);
    } finally {
        app()->instance($key, $original);
    }
});
