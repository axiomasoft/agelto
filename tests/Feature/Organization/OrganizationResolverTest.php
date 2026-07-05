<?php

declare(strict_types=1);

use App\Dto\Crawler\Details\OrganizationData;
use App\Models\Organization\Organization;
use App\Services\Organization\OrganizationResolver;

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
