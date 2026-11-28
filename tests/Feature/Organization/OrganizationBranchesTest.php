<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use App\Models\Procurement\Procurement;
use App\Services\Organization\OrganizationResolver;
use App\Services\Procurement\Detail\OrganizationPartiesProcessor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('филиалы с общими ИНН и ОГРН разрешаются в отдельные записи по КПП', function (): void {
    $resolver = app(OrganizationResolver::class);
    $first = $resolver->resolve(new OrganizationData(fullName: 'Первый филиал', inn: '7701234567', kpp: '770101001', ogrn: '1027700123456'));
    $second = $resolver->resolve(new OrganizationData(fullName: 'Второй филиал', inn: '7701234567', kpp: '770102001', ogrn: '1027700123456'));

    expect($second->id)->not->toBe($first->id)
        ->and($resolver->resolve(new OrganizationData(fullName: 'Второй филиал', kpp: '770102001', ogrn: '1027700123456'))->id)->toBe($second->id)
        ->and(Organization::query()->count())->toBe(2);

    $unspecified = $resolver->resolve(new OrganizationData(fullName: 'Организация без КПП', inn: '7701234567', ogrn: '1027700123456'));
    expect($unspecified->kpp)->toBeNull()
        ->and($unspecified->id)->not->toBeIn([$first->id, $second->id])
        ->and($first->refresh()->full_name)->toBe('Первый филиал')
        ->and($second->refresh()->full_name)->toBe('Второй филиал');
});

test('наблюдение с КПП дополняет запись по ИНН и сохраняет более свежие реквизиты', function (): void {
    $resolver = app(OrganizationResolver::class);
    $recent = new DateTimeImmutable('2026-09-20T10:00:00.900000Z');
    $earlier = new DateTimeImmutable('2026-09-20T10:00:00.500000Z');
    $first = $resolver->resolve(data: new OrganizationData(fullName: 'Новое имя', inn: '7701234567',
        addressRaw: 'Новый адрес', phone: '020', email: 'new@example.test'), observedAt: $recent);
    $updated = $resolver->resolve(data: new OrganizationData(fullName: 'Архивное имя', inn: '7701234567', kpp: '770101001',
        ogrn: '1027700123456', addressRaw: 'Старый адрес', phone: '010', email: 'old@example.test'), observedAt: $earlier)->refresh();

    expect($updated->id)->toBe($first->id)
        ->and($updated->kpp)->toBe('770101001')
        ->and($updated->ogrn)->toBe('1027700123456')
        ->and($updated->full_name)->toBe('Новое имя')
        ->and($updated->address_raw)->toBe('Новый адрес')
        ->and($updated->phone)->toBe('020')
        ->and($updated->email)->toBe('new@example.test')
        ->and($updated->attributes_observed_at?->format('Y-m-d H:i:s.uP'))->toBe('2026-09-20 10:00:00.900000+00:00');

    $sameAttributes = new OrganizationData(fullName: 'Новое имя', inn: '7701234567', kpp: '770101001');
    $resolver->resolve(data: $sameAttributes, observedAt: $recent->modify('+2 days'));
    $updated = $resolver->resolve(data: new OrganizationData(fullName: 'Промежуточное имя', inn: '7701234567', kpp: '770101001'),
        observedAt: $recent->modify('+1 day'));
    expect($updated->full_name)->toBe('Новое имя')
        ->and($updated->attributes_observed_at?->toDateString())->toBe('2026-09-22')
        ->and(Organization::query()->count())->toBe(1);
});

test('обработка архивной карточки сохраняет реквизиты из более свежей закупки', function (): void {
    $processor = app(OrganizationPartiesProcessor::class);
    $recent = Procurement::factory()->create(['source_updated_at' => '2026-09-20 10:00:00']);
    $archive = Procurement::factory()->create(['source_updated_at' => '2020-09-20 10:00:00']);

    $processor->process(procurement: $recent, parties: [new PartyData(
        role: ProcurementPartyRole::Customer, organization: new OrganizationData(fullName: 'Актуальный заказчик', inn: '7701234567', kpp: '770101001'),
    )]);
    $processor->process(procurement: $archive, parties: [new PartyData(
        role: ProcurementPartyRole::Customer, organization: new OrganizationData(fullName: 'Архивный заказчик', inn: '7701234567', kpp: '770101001'),
    )]);

    $organization = Organization::query()->sole();
    expect($organization->full_name)->toBe('Актуальный заказчик')
        ->and($organization->attributes_observed_at?->toDateString())->toBe('2026-09-20')
        ->and($archive->refresh()->customer_org_id)->toBe($organization->id);
});

test('после двух конфликтов уникальности организация сохраняется третьей попыткой', function (): void {
    $attempts = 0;
    DB::listen(function (QueryExecuted $query) use (&$attempts): void {
        if (str_starts_with($query->sql, 'insert into "org_organizations"') && ++$attempts < 3) {
            throw new UniqueConstraintViolationException(connectionName: $query->connectionName, sql: $query->sql,
                bindings: $query->bindings, previous: new PDOException('Тестовый конфликт уникальности', 23505));
        }
    });

    $organization = app(OrganizationResolver::class)->resolve(new OrganizationData(fullName: 'Филиал', inn: '7701234567', kpp: '770101001'));

    expect($attempts)->toBe(3)->and(Organization::query()->sole()->id)->toBe($organization->id);
});
