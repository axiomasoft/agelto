<?php

declare(strict_types=1);

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Models\Organization\Organization;
use App\Models\Organization\OrganizationIdentifier;
use App\Services\Organization\OrganizationResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('resolve создаёт новую организацию при отсутствии совпадения по ИНН/ОГРН', function () {
    $organization = app(OrganizationResolver::class)->resolve(new OrganizationData(
        fullName: 'ООО «Ромашка»',
        inn: '7701234567',
        kpp: '770101001',
        ogrn: '1027700123456',
    ));

    expect(Organization::query()->count())->toBe(1)
        ->and($organization->full_name)->toBe('ООО «Ромашка»')
        ->and($organization->inn)->toBe('7701234567');
});

test('resolve возвращает ту же запись для повторного резолва по тому же ИНН+КПП', function () {
    $resolver = app(OrganizationResolver::class);

    $data = new OrganizationData(fullName: 'ООО «Ромашка»', inn: '7701234567', kpp: '770101001', ogrn: '1027700123456');

    $first = $resolver->resolve($data);
    $second = $resolver->resolve($data);

    expect(Organization::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

test('resolve обновляет изменившееся наименование при повторном резолве', function () {
    $resolver = app(OrganizationResolver::class);

    $resolver->resolve(new OrganizationData(fullName: 'ООО «Ромашка»', inn: '7701234567', kpp: '770101001'));
    $updated = $resolver->resolve(new OrganizationData(fullName: 'ООО «Ромашка Плюс»', inn: '7701234567', kpp: '770101001'));

    expect(Organization::query()->count())->toBe(1)
        ->and($updated->full_name)->toBe('ООО «Ромашка Плюс»');
});

test('resolve находит организацию по ОГРН, если ИНН не указан', function () {
    $existing = Organization::factory()->create(['inn' => null, 'kpp' => null, 'ogrn' => '1027700123456']);

    $organization = app(OrganizationResolver::class)->resolve(new OrganizationData(
        fullName: $existing->full_name,
        ogrn: '1027700123456',
    ));

    expect(Organization::query()->count())->toBe(1)
        ->and($organization->id)->toBe($existing->id);
});

test('внешние идентификаторы различают площадки и типы независимо от названия', function () {
    $resolver = app(OrganizationResolver::class);
    $resolve = fn (string $platform, string $type, string $value, string $name = 'Одинаковое имя') => $resolver->resolve(
        new OrganizationData(fullName: $name, identifiers: [new OrganizationIdentifierData(platformCode: $platform, type: $type, value: $value)]),
    );
    $first = $resolve('market-a', 'organization', '000123');
    $second = $resolve('market-b', 'organization', '000123');
    $customer = $resolve('market-a', 'customer', '000123');
    $repeat = $resolve('market-a', 'organization', '000123', 'Новое имя');

    expect(Organization::query()->count())->toBe(3)
        ->and(OrganizationIdentifier::query()->count())->toBe(3)
        ->and($second->id)->not->toBe($first->id)
        ->and($customer->id)->not->toBe($first->id)
        ->and($repeat->id)->toBe($first->id)
        ->and($repeat->full_name)->toBe('Новое имя')
        ->and($repeat->inn)->toBeNull()
        ->and($repeat->ogrn)->toBeNull()
        ->and($repeat->identifiers->sole()->value)->toBe('000123');
});

test('налоговые реквизиты и идентификаторы нескольких площадок дополняют одну организацию', function () {
    $resolver = app(OrganizationResolver::class);
    $identifier = new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '000123');
    $customer = new OrganizationIdentifierData(platformCode: 'market-b', type: 'customer', value: 'customer-42');
    $first = $resolver->resolve(new OrganizationData(fullName: 'Заказчик', identifiers: [$identifier]));
    $enriched = $resolver->resolve(new OrganizationData(
        fullName: 'Заказчик', inn: '0253020551', kpp: '025301001', identifiers: [$identifier, $customer],
    ));
    $byTax = $resolver->resolve(new OrganizationData(fullName: 'Заказчик', inn: '0253020551', kpp: '025301001'));
    $byOtherPlatform = $resolver->resolve(new OrganizationData(fullName: 'Заказчик', identifiers: [$customer]));

    expect(Organization::query()->count())->toBe(1)
        ->and($enriched->id)->toBe($first->id)
        ->and($enriched->identifiers)->toHaveCount(2)
        ->and($byTax->id)->toBe($first->id)
        ->and($byOtherPlatform->id)->toBe($first->id)
        ->and($byTax->identifiers)->toHaveCount(2);
});

test('противоречивые ключи не объединяют разные master записи', function () {
    $resolver = app(OrganizationResolver::class);
    $identifier = new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '000123');
    $first = $resolver->resolve(new OrganizationData(fullName: 'Первый', identifiers: [$identifier]));
    $second = Organization::factory()->create(['inn' => '0253020551', 'kpp' => '025301001']);

    expect(fn () => $resolver->resolve(new OrganizationData(
        fullName: 'Неверное слияние', inn: '0253020551', kpp: '025301001', identifiers: [$identifier],
    )))->toThrow(RuntimeException::class);

    expect($first->refresh()->full_name)->toBe('Первый')
        ->and($second->identifiers)->toBeEmpty()
        ->and(Organization::query()->count())->toBe(2);
});

test('разные внешние ключи уже существующих организаций запрещают автоматическое слияние', function () {
    $resolver = app(OrganizationResolver::class);
    $first = new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '001');
    $second = new OrganizationIdentifierData(platformCode: 'market-b', type: 'customer', value: '002');
    $resolver->resolve(new OrganizationData(fullName: 'Первый', identifiers: [$first]));
    $resolver->resolve(new OrganizationData(fullName: 'Второй', identifiers: [$second]));

    expect(fn () => $resolver->resolve(new OrganizationData(fullName: 'Слияние', identifiers: [$first, $second])))
        ->toThrow(RuntimeException::class);
    expect(Organization::query()->pluck('full_name')->all())->toBe(['Первый', 'Второй']);
});

test('замена известного внешнего ключа не меняет реквизиты и не сохраняет новые ключи', function () {
    $resolver = app(OrganizationResolver::class);
    $identifier = new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '001');
    $organization = $resolver->resolve(new OrganizationData(fullName: 'Первый', inn: '0253020551', identifiers: [$identifier]));

    expect(fn () => $resolver->resolve(new OrganizationData(fullName: 'Изменённый', inn: '0253020551', identifiers: [
        new OrganizationIdentifierData(platformCode: 'market-b', type: 'customer', value: 'new'),
        new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: 'conflict'),
    ])))->toThrow(RuntimeException::class);

    expect($organization->refresh()->full_name)->toBe('Первый')
        ->and($organization->identifiers)->toHaveCount(1)
        ->and(OrganizationIdentifier::query()->count())->toBe(1);
});

test('резолвер отказывает названию без идентификаторов', function () {
    expect(fn () => app(OrganizationResolver::class)->resolve(new OrganizationData(fullName: 'Только имя')))
        ->toThrow(InvalidArgumentException::class);
    expect(Organization::query()->count())->toBe(0);
});

test('гонка создания внешнего ключа откатывает лишнюю организацию и повторяет полный поиск', function () {
    Organization::factory()->create(['inn' => null, 'kpp' => null]);
    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected): void {
        if (! $injected && str_starts_with($query->sql, 'select') && in_array('000123', $query->bindings, true)) {
            $injected = true;
            // Emulate a competing identity committed after the initial lookup returned no row.
            $organization = Organization::factory()->create(['inn' => null, 'kpp' => null]);
            $organization->identifiers()->create(['platform_code' => 'market-a', 'type' => 'organization', 'value' => '000123']);
        }
    });
    $resolved = app(OrganizationResolver::class)->resolve(new OrganizationData(fullName: 'Верная организация', identifiers: [
        new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '000123'),
    ]));

    expect($injected)->toBeTrue()
        ->and(Organization::query()->count())->toBe(2)
        ->and(OrganizationIdentifier::query()->count())->toBe(1)
        ->and($resolved->identifiers->sole()->value)->toBe('000123')
        ->and($resolved->full_name)->toBe('Верная организация');
});
