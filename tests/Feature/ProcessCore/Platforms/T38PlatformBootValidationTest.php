<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\AdapterVersion;
use App\Dto\Procurement\Platform\DiagnosticChecks;
use App\Dto\Procurement\Platform\EndpointCatalog;
use App\Dto\Procurement\Platform\EndpointDefinition;
use App\Dto\Procurement\Platform\ExtensionDescriptor;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\RegistryDefinition;
use App\Dto\Procurement\Platform\RegistryDefinitions;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Platforms\Eis\Diagnostics\ListingFilterCheck;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Procurement\Network\Contracts\DiagnosticCheck;
use App\Services\Procurement\Platform\Contracts\ExtensionPersister;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;
use App\Services\Procurement\Platform\PlatformBootValidator;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;

pest()->group('t38', 'p22');

final readonly class T38FixtureExtension {}

/** @implements ExtensionPersister<T38FixtureExtension> */
final class T38FixtureWriter implements ExtensionPersister
{
    public function __construct()
    {
        throw new LogicException('Validation must not construct writers.');
    }

    public function persist(int $procurementId, object $extension): void
    {
        throw new LogicException('Validation must not execute writers.');
    }
}

final class T38NeedsRuntimeProvider implements PlatformDefinitionProvider
{
    public function __construct(PDO $database)
    {
        throw new LogicException('Validation must reject runtime dependencies before construction.');
    }

    public function definition(): PlatformDefinition
    {
        throw new LogicException('Not a config-only provider.');
    }
}

/** @param array<string, mixed> $changes */
function t38Definition(array $changes = []): PlatformDefinition
{
    return new PlatformDefinition(...array_replace(get_object_vars((new JsonProcurementDefinition)->definition()), $changes));
}

test('T38 единый registry читает ЕИС и JSON из config без DB HTTP или создания adapters', function (): void {
    $config = new Repository(config()->all());
    $config->set('platforms.definitions.json', JsonProcurementDefinition::class);
    DB::shouldReceive('connection')->never();
    Http::preventStrayRequests();

    $registry = new PlatformRegistry($config);
    $eis = $registry->get(new PlatformCode('eis'));
    $json = $registry->get(new PlatformCode('json'));
    expect(array_map(fn (PlatformDefinition $definition) => $definition->code->value, $registry->all()))->toBe(['eis', 'json'])
        ->and($eis->registries->get('fz44')->primaryIdentityType->value)->toBe('registry:44fz')
        ->and($json->registries->get('fz44')->primaryIdentityType->value)->toBe('opaque:notice');
    // Reflect a real diagnostic whose constructor requires the HTTP adapter; never instantiate it.
    (new PlatformBootValidator)->validate(t38Definition(['checks' => new DiagnosticChecks(['listing' => ListingFilterCheck::class])]), 'json');
    Http::assertNothingSent();
});

test('T38 неверный provider code и повторная регистрация отвергаются без fallback', function (): void {
    foreach ([
        ['wrong' => EisDefinition::class],
        ['eis' => EisDefinition::class, 'other' => EisDefinition::class],
        ['eis' => stdClass::class],
        ['eis' => 'MissingDefinitionProvider'],
        ['eis' => 123],
        ['eis' => T38NeedsRuntimeProvider::class],
    ] as $registrations) {
        $config = new Repository(config()->all());
        $config->set('platforms.definitions', $registrations);
        expect(fn () => new PlatformRegistry($config))->toThrow(InvalidPlatformDefinition::class);
    }
    expect(fn () => (new PlatformRegistry(config()))->get(new PlatformCode('missing')))
        ->toThrow(InvalidPlatformDefinition::class, 'Unregistered platform');
});

test('T38 target origin profile и extension DTO pairing проверяются до constructor I/O', function (): void {
    $validator = new PlatformBootValidator;
    $definition = t38Definition();
    $endpoint = $definition->endpoints->get('detail');
    $wrong = new EndpointDefinition('detail', 'json.detail', 'https://wrong.example/detail', $endpoint->profile);
    expect(fn () => $validator->validate(t38Definition(['endpoints' => new EndpointCatalog([$wrong])]), 'json'))
        ->toThrow(InvalidPlatformDefinition::class, 'target/origin/profile mismatch');
    $version = new AdapterVersion('1');
    $descriptor = new ExtensionDescriptor(T38FixtureExtension::class, T38FixtureWriter::class, $version, $version);
    $validator->validate(t38Definition(['extension' => $descriptor]), 'json');
    $mismatched = new ExtensionDescriptor(SourceIdentifierValue::class, T38FixtureWriter::class, $version, $version);
    expect(fn () => $validator->validate(t38Definition(['extension' => $mismatched]), 'json'))
        ->toThrow(InvalidPlatformDefinition::class, 'exact fully qualified DTO');
    $wrongVersion = new ExtensionDescriptor(T38FixtureExtension::class, T38FixtureWriter::class, $version, new AdapterVersion('2'));
    expect(fn () => $validator->validate(t38Definition(['extension' => $wrongVersion]), 'json'))
        ->toThrow(InvalidPlatformDefinition::class, 'adapter version mismatch');
});

test('T38 config cache сохраняет definitions и вложенную policy одновременно', function (): void {
    $cache = sys_get_temp_dir().'/p29-config.php';
    try {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'config:cache'],
            base_path(),
            ['APP_CONFIG_CACHE' => $cache, 'APP_ENV' => 'testing'],
        );
        $process->mustRun();
        $cached = new Repository(require $cache);
        expect($cached->get('platforms.definitions.eis'))->toBe(EisDefinition::class)
            ->and($cached->get('platforms.eis.block_size'))->toBe(500)
            ->and((new PlatformRegistry($cached))->get(new PlatformCode('eis'))->code->value)->toBe('eis');
    } finally {
        if (is_file($cache)) {
            unlink($cache);
        }
    }
});

test('T38 advertised missing wrong abstract implementations и unknown endpoints fail closed', function (): void {
    $validator = new PlatformBootValidator;
    foreach (['MissingListingSource', stdClass::class] as $class) {
        expect(fn () => $validator->validate(t38Definition(['listing' => new SupportedListingCapability($class, ['detail'])]), 'json'))
            ->toThrow(InvalidPlatformDefinition::class, 'platforms.definitions.json.listing');
    }
    foreach (['MissingCheck', stdClass::class, DiagnosticCheck::class] as $class) {
        expect(fn () => $validator->validate(t38Definition(['checks' => new DiagnosticChecks(['check' => $class])]), 'json'))
            ->toThrow(InvalidPlatformDefinition::class, 'platforms.definitions.json.checks.check');
    }
    expect(fn () => t38Definition()->endpoints->get('missing'))->toThrow(InvalidPlatformDefinition::class, 'Unknown endpoint');
});

test('T38 duplicate registry code и independently numbered identity namespace отвергаются', function (): void {
    foreach ([
        [new RegistryDefinition('one', new SourceIdentifierType('one')), new RegistryDefinition('one', new SourceIdentifierType('two'))],
        [new RegistryDefinition('one', new SourceIdentifierType('shared')), new RegistryDefinition('two', new SourceIdentifierType('shared'))],
    ] as $registries) {
        expect(fn () => new RegistryDefinitions($registries))->toThrow(InvalidPlatformDefinition::class, 'Duplicate registry');
    }
});

test('T38 opaque identity сохраняет нули регистр whitespace Unicode и platform scope', function (): void {
    $definition = t38Definition();
    $encoded = [];
    foreach (['00042', '42', 'Ab-1', 'ab-1', ' 42 ', 'é', "e\u{0301}"] as $value) {
        $identity = new SourceIdentity(new PlatformCode('json'), new SourceIdentifierType('opaque:notice'), new SourceIdentifierValue($value));
        $definition->validateIdentity('fz44', $identity);
        expect(SourceIdentity::fromArray($identity->toArray())->toArray())->toBe(['platform' => 'json', 'type' => 'opaque:notice', 'value' => $value]);
        $encoded[] = json_encode($identity->toArray(), JSON_THROW_ON_ERROR);
    }
    expect(count(array_unique($encoded)))->toBe(7);
    foreach ([
        new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType('opaque:notice'), new SourceIdentifierValue('42')),
        new SourceIdentity(new PlatformCode('json'), new SourceIdentifierType('registry:44fz'), new SourceIdentifierValue('42')),
    ] as $identity) {
        expect(fn () => $definition->validateIdentity('fz44', $identity))->toThrow(InvalidPlatformDefinition::class);
    }
});

test('T38 identity invalid NUL UTF8 длина type и coercion запрещены', function (): void {
    foreach (['', "\0", "\xFF", str_repeat('я', 201)] as $value) {
        expect(fn () => new SourceIdentifierValue($value))->toThrow(InvalidPlatformDefinition::class);
    }
    expect((new SourceIdentifierValue(str_repeat('я', 200)))->value)->toBe(str_repeat('я', 200));
    foreach (['', 'UPPER', ' bad', str_repeat('a', 97)] as $type) {
        expect(fn () => new SourceIdentifierType($type))->toThrow(InvalidPlatformDefinition::class);
    }
    foreach ([
        ['platform' => 'json', 'type' => 'opaque:notice', 'value' => 42],
        ['platform' => 'json', 'type' => 'opaque:notice', 'value' => '42', 'alias' => 'other'],
    ] as $data) {
        expect(fn () => SourceIdentity::fromArray($data))->toThrow(InvalidPlatformDefinition::class);
    }
});

test('T38 CLI validate не синхронизирует DB catalog и возвращает явную ошибку', function (): void {
    DB::shouldReceive('connection')->never();
    $this->artisan('platform:validate', ['code' => 'eis'])->expectsOutput('eis: definition 1 valid')->assertExitCode(0);
    $this->artisan('platform:validate', ['code' => 'unknown'])->assertExitCode(1);
    config(['platforms.definitions.eis' => stdClass::class]);
    $this->artisan('platform:validate')->assertExitCode(1);
});
