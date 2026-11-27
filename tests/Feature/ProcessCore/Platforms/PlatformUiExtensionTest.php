<?php

declare(strict_types=1);

use App\Contracts\Procurement\Platform\PlatformUiExtension;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Platform\PlatformUiResolver;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;

pest()->group('platform-definition-boot');

final class ExampleUiExtension implements PlatformUiExtension
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function profileFormSchema(): array
    {
        return [Section::make('Fixture profile')];
    }

    public function procurementInfolistSections(Procurement $procurement): array
    {
        return [Section::make('Fixture procurement')];
    }
}

final readonly class ExampleUiDefinition implements PlatformDefinitionProvider
{
    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(...array_replace(get_object_vars((new JsonProcurementDefinition)->definition()), [
            'uiExtension' => ExampleUiExtension::class,
        ]));
    }
}

test('optional UI seam resolves only declared extensions and leaves JSON common view untouched', function (): void {
    config()->set('platforms.definitions', ['json' => JsonProcurementDefinition::class]);
    $resolver = app(PlatformUiResolver::class);
    $common = [Section::make('Common')];
    $extension = $resolver->resolve(new PlatformCode('json'));
    expect($extension)->toBeNull()->and([...$common, ...($extension?->profileFormSchema() ?? [])])->toBe($common);
    expect(fn () => $resolver->resolve(new PlatformCode('missing')))->toThrow(InvalidPlatformDefinition::class);

    config()->set('platforms.definitions.json', ExampleUiDefinition::class);
    ExampleUiExtension::$constructed = 0;
    new PlatformRegistry(config());
    expect(ExampleUiExtension::$constructed)->toBe(0);
    $extension = app(PlatformUiResolver::class)->resolve(new PlatformCode('json'));
    expect($extension)->toBeInstanceOf(ExampleUiExtension::class)
        ->and($extension->profileFormSchema()[0]->getHeading())->toBe('Fixture profile')
        ->and($extension->procurementInfolistSections(new Procurement)[0]->getHeading())->toBe('Fixture procurement');
    app()->bind(ExampleUiExtension::class, fn () => new stdClass);
    expect(fn () => app(PlatformUiResolver::class)->resolve(new PlatformCode('json')))
        ->toThrow(InvalidPlatformDefinition::class, 'incompatible container binding');
});

test('platform validate checks actual definitions and UI descriptors without database HTTP or component construction', function (): void {
    config()->set('platforms.definitions.json', ExampleUiDefinition::class);
    ExampleUiExtension::$constructed = 0;
    Http::preventStrayRequests();
    DB::enableQueryLog();
    DB::flushQueryLog();
    test()->artisan('platform:validate')->expectsOutputToContain('eis: definition 1 valid')->assertSuccessful();
    test()->artisan('platform:validate', ['code' => 'json'])->expectsOutputToContain('json: definition 1 valid')->assertSuccessful();
    test()->artisan('platform:validate', ['code' => 'missing'])->assertFailed();
    config()->set('platforms.eis.block_size', 0);
    test()->artisan('platform:validate')->expectsOutputToContain('platforms.eis.block_size')->assertFailed();
    expect(ExampleUiExtension::$constructed)->toBe(0)->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    Http::assertNothingSent();
});
