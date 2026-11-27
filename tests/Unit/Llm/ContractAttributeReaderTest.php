<?php

declare(strict_types=1);

use App\Attributes\Ai\Alias;
use App\Attributes\Ai\Description;
use App\Services\Llm\Schema\ContractAttributeReader;
use Spatie\LaravelData\Data;

enum ContractAttributeReaderFixtureKind: string
{
    #[Alias(aliases: ['материал', 'мат'])]
    case Material = 'material';

    case Other = 'other';
}

final class ContractAttributeReaderFixtureNested extends Data
{
    public function __construct(
        #[Description('вложенное поле с описанием.')]
        public string $inner,
    ) {}
}

final class ContractAttributeReaderFixtureRoot extends Data
{
    public function __construct(
        #[Description('поле с описанием и алиасами.')]
        #[Alias(aliases: ['Синоним 1', 'Синоним 2'])]
        public string $described,
        public ?string $bare = null,
    ) {}
}

test('отдаёт описание и алиасы для свойства, несущего оба атрибута', function (): void {
    $reader = new ContractAttributeReader;

    expect($reader->descriptions(ContractAttributeReaderFixtureRoot::class))
        ->toBe(['described' => 'поле с описанием и алиасами.'])
        ->and($reader->aliases(ContractAttributeReaderFixtureRoot::class))
        ->toBe(['described' => ['Синоним 1', 'Синоним 2']]);
});

test('свойство без атрибутов не несёт ключа — не пустую строку и не исключение', function (): void {
    $reader = new ContractAttributeReader;

    expect($reader->descriptions(ContractAttributeReaderFixtureRoot::class))->not->toHaveKey('bare')
        ->and($reader->aliases(ContractAttributeReaderFixtureRoot::class))->not->toHaveKey('bare');
});

test('вложенный контракт читается тем же ридером независимо от корня', function (): void {
    $reader = new ContractAttributeReader;

    expect($reader->descriptions(ContractAttributeReaderFixtureNested::class))
        ->toBe(['inner' => 'вложенное поле с описанием.']);
});

test('caseAliases отдаёт синонимы кейсов enum через #[Alias], кейс без атрибута — без ключа', function (): void {
    $reader = new ContractAttributeReader;

    expect($reader->caseAliases(ContractAttributeReaderFixtureKind::class))
        ->toBe(['material' => ['материал', 'мат']])
        ->and($reader->caseAliases(ContractAttributeReaderFixtureKind::class))->not->toHaveKey('other');
});
