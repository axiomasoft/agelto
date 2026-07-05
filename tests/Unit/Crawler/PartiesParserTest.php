<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Services\Crawler\Eis\Card\PartiesParser;

function partiesFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/common-info.html'));
}

test('parse разбирает известные роли организаций из вкладки common-info', function () {
    $parties = app(PartiesParser::class)->parse(partiesFixtureHtml());

    // 4 строки в фикстуре, 1 с неизвестной ролью «Оператор торгов» — пропущена.
    expect($parties)->toHaveCount(3);

    expect($parties[0]->role)->toBe(ProcurementPartyRole::Customer)
        ->and($parties[0]->organization->fullName)->toBe('Администрация городского округа')
        ->and($parties[0]->organization->inn)->toBe('5001234567')
        ->and($parties[0]->organization->kpp)->toBe('500101001')
        ->and($parties[0]->organization->ogrn)->toBe('1025001234567')
        ->and($parties[0]->contactPersonName)->toBe('Иванов Иван Иванович')
        ->and($parties[0]->contactPersonPosition)->toBe('Главный специалист');

    expect($parties[1]->role)->toBe(ProcurementPartyRole::Placer)
        ->and($parties[1]->organization->inn)->toBe('5001234567')
        ->and($parties[1]->contactPersonName)->toBeNull();

    expect($parties[2]->role)->toBe(ProcurementPartyRole::AuthorizedBody)
        ->and($parties[2]->organization->fullName)->toBe('Департамент государственного заказа Московской области');
});

test('parse пропускает неизвестную роль-строку без падения', function () {
    $parties = app(PartiesParser::class)->parse(partiesFixtureHtml());

    expect(collect($parties)->pluck('organization.fullName'))->not->toContain('ООО «Неизвестный оператор»');
});

test('parse возвращает пустой список при отсутствии блока organizations-block', function () {
    $html = preg_replace('#<section class="organizations-block">.*?</section>#s', '', partiesFixtureHtml());

    $parties = app(PartiesParser::class)->parse($html);

    expect($parties)->toBe([]);
});
