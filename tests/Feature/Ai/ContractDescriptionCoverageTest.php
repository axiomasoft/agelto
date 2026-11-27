<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Services\Llm\Schema\ContractAttributeReader;
use App\Services\Llm\Schema\DataTypeGraph;

/**
 * Причины, по которым свойствам не нужен #[Description].
 *
 * @return array<string, string>
 */
function contractDescriptionCoverageExceptions(): array
{
    return [];
}

test('каждое свойство каждого Data-контракта, достижимого от обёртки ответа шага, несёт #[Description] либо поимённое исключение с причиной', function (): void {
    $exceptions = contractDescriptionCoverageExceptions();

    $types = new DataTypeGraph;
    $reader = new ContractAttributeReader;

    $roots = [
        ScreeningResult::class,
        DiscoveryResponse::class,
        PassportResponse::class,
        RowsChunkResponse::class,
        TotalsResponse::class,
    ];

    $uncovered = [];

    foreach ($roots as $root) {
        foreach ($types->closure($root) as $class) {
            if (! $types->isDataClass($class)) {
                continue;
            }

            $descriptions = $reader->descriptions($class);
            $shortName = new ReflectionClass($class)->getShortName();
            $constructor = new ReflectionClass($class)->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $key = "{$shortName}::\${$parameter->getName()}";

                if (isset($descriptions[$parameter->getName()]) || array_key_exists($key, $exceptions)) {
                    continue;
                }

                $uncovered[] = $key;
            }
        }
    }

    expect($uncovered)->toBe([]);
});

test('каждое исключение покрытия несёт непустую однострочную причину', function (): void {
    foreach (contractDescriptionCoverageExceptions() as $property => $reason) {
        expect($property)->toBeString()
            ->and($reason)->toBeString()->not->toBe('');
    }

    expect(contractDescriptionCoverageExceptions())->toBeArray();
});
