<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Services\Platforms\Eis\Csv\EisCsvParser;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Config\Repository;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\Platforms\Contract\AdapterContractKit;
use Tests\Support\Platforms\Contract\ContractCase;
use Tests\Support\Platforms\Contract\ContractManifest;
use Tests\Support\Platforms\Contract\ContractRegistry;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\FixtureCorpus;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Contract\SemanticObservation;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\ProcessCore\ContractFixtures;

pest()->group('t39', 'p22');

test('T39 registered capability manifests исполняют golden semantics обеих площадок', function (): void {
    $manifests = ContractRegistry::manifests();
    $config = new Repository(config()->all());
    $config->set('platforms.definitions.json', JsonProcurementDefinition::class);
    $definitions = (new PlatformRegistry($config))->all();
    (new AdapterContractKit)->verifyRegistry($definitions, $manifests);
    foreach ($manifests as $code => $manifest) {
        expect($manifest->definition->code->value)->toBe($code);
        $executed = array_map(fn (ContractCase $case): string => $case->key(), $manifest->cases);
        expect($executed)->toContain('listing.empty', 'listing.truncated', 'listing.overflow', 'listing.loop', 'detail.required_sections');
    }
    unset($manifests['eis']);
    expect(fn () => (new AdapterContractKit)->verifyRegistry($definitions, $manifests))
        ->toThrow(LogicException::class, 'Missing capability manifest: eis');
});

test('T39 удаление каждого required semantic check или fixture обнаруживается', function (): void {
    $kit = new AdapterContractKit;
    foreach (ContractRegistry::manifests() as $manifest) {
        foreach ($manifest->cases as $index => $case) {
            $remaining = $manifest->cases;
            unset($remaining[$index]);
            expect(fn () => $kit->verify(new ContractManifest($manifest->definition, array_values($remaining), $manifest->additionalRequired)))
                ->toThrow(LogicException::class, 'Missing semantic check: '.$case->key());
            $missing = $manifest->cases;
            $missing[$index] = new ContractCase($case->capability, $case->scenario, [], $case->expected, $case->observe);
            expect(fn () => $kit->verify(new ContractManifest($manifest->definition, $missing, $manifest->additionalRequired)))
                ->toThrow(LogicException::class, 'Missing fixture: '.$case->key());
        }
    }
    expect(fn () => FixtureCorpus::read('tests/Fixtures/Platforms/Eis/Csv/missing.csv'))
        ->toThrow(LogicException::class, 'Unregistered contract fixture');
});

test('T39 настоящая parser mutation без overflow guard нарушает общий golden contract', function (): void {
    $path = (new ReflectionClass(EisListingSource::class))->getFileName();
    $source = file_get_contents($path);
    $mutant = str_replace('final readonly class EisListingSource', 'final readonly class ContractOverflowMutant', $source, $renames);
    $mutant = str_replace('if ($count > $this->policy->blockSize)', 'if (false)', $mutant, $mutations);
    expect([$renames, $mutations])->toBe([1, 1]);
    // Isolated in-memory class; the product source and working tree are never mutated.
    eval(substr($mutant, strlen('<?php')));
    $class = 'App\\Services\\Platforms\\Eis\\ContractOverflowMutant';
    $adapter = new $class(new EisPolicy(config()), new RecordingOutbound, ContractFixtures::id());
    $case = array_values(array_filter(EisContractManifest::manifest()->cases, fn (ContractCase $case): bool => $case->key() === 'listing.overflow'))[0];
    $mutation = new ContractCase($case->capability, $case->scenario, $case->fixtures, $case->expected,
        fn (): SemanticObservation => SemanticObservation::listing($adapter->parse(EisContractManifest::request(),
            ContractRuntime::response(FixtureCorpus::read($case->fixtures[0]), 'text/csv'))));
    expect(fn () => (new AdapterContractKit)->verifyCase($mutation))->toThrow(ExpectationFailedException::class, 'Semantic contract mismatch');
    expect($case->expected->reason)->toBe(AssessmentReason::Overflow);
});

test('T39 прежние parser guards и datasets сохранены без удаления сценариев', function (): void {
    $inventory = json_decode(file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parser-scenarios.json')), true, 512, JSON_THROW_ON_ERROR);
    $count = 0;
    foreach ($inventory['files'] as $entry) {
        expect(is_file(base_path($entry['path'])))->toBeTrue();
        expect(hash_file('sha256', base_path($entry['path'])))->toBe($entry['sha256']);
        $source = file_get_contents(base_path($entry['path']));
        foreach ($entry['scenarios'] as $scenario) {
            expect($source)->toContain($scenario);
            $count++;
        }
    }
    expect($count)->toBe(133);
});

test('T39 сохранены точные CSV resource bounds и UTF8 BOM', function (): void {
    $parser = new EisCsvParser;
    $header = FixtureCorpus::read(EisContractManifest::CSV.'neutral-empty.csv');
    $valid = FixtureCorpus::read(EisContractManifest::CSV.'neutral-valid.csv');
    expect($parser->parse("\xEF\xBB\xBF".$valid))->toBe($parser->parse($valid));
    $row = "00042;Работы;Подача заявок;31.10.2026;31.10.2026;1;RUB\n";
    expect($parser->parseBatch($header.str_repeat($row, 1000))['records'])->toBe(1000);
    expect(fn () => $parser->parseBatch($header.str_repeat($row, 1001)))
        ->toThrow(SourceResponseLimitExceeded::class);
    expect($parser->parseBatch("field\n".str_repeat('x', 262144))['records'])->toBe(1);
    expect(fn () => $parser->parseBatch("field\n".str_repeat('x', 262145)))
        ->toThrow(SourceResponseLimitExceeded::class);
    $columns = implode(';', array_map(fn (int $i): string => 'c'.$i, range(1, 256)));
    expect($parser->parseBatch($columns)['header'])->toHaveCount(256);
    expect(fn () => $parser->parseBatch($columns.';extra'))
        ->toThrow(SourceResponseLimitExceeded::class);
    expect(fn () => $parser->parseBatch(str_repeat(';', 128001)))
        ->toThrow(SourceResponseLimitExceeded::class, 'separator count');
});
