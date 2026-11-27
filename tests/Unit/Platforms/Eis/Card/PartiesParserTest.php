<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Services\Platforms\Eis\Card\PartiesParser;
use Symfony\Component\DomCrawler\Crawler;

function partiesFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/common-info.html'));
}

test('реальная январская карточка различает заказчика и размещающую организацию', function (int $id, ProcurementPartyRole $role, string $organizationCode, string $customerCode) {
    $html = file_get_contents(base_path("tests/Fixtures/Platforms/Eis/Card/parties-january-{$id}.html"));
    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html);
    $customer = collect($parties)->firstWhere('role', ProcurementPartyRole::Customer);

    expect($parser->authorizesReconciliation($html, $parties))->toBeTrue()
        ->and($parties)->toHaveCount($role === ProcurementPartyRole::Customer ? 2 : 3)
        ->and($parties[0]->role)->toBe(ProcurementPartyRole::Placer)
        ->and($parties[1]->role)->toBe($role)
        ->and(collect($parties[0]->organization->identifiers)->firstWhere('type', 'organization_code')->value)->toBe($organizationCode)
        ->and(collect($customer->organization->identifiers)->firstWhere('type', 'customer_code')->value)->toBe($customerCode)
        ->and($customer->organization->inn)->toBeNull()
        ->and($customer->organization->ogrn)->toBeNull()
        ->and($parties[0]->contactPersonName)->toBe('Иванов И. И.');

    if ($role !== ProcurementPartyRole::Customer) {
        expect($customer->organization->fullName)->not->toBe($parties[0]->organization->fullName)
            ->and($customer->contactPersonName)->toBeNull()
            ->and($customer->organization->email)->toBeNull();
    } else {
        expect($customer->organization)->toBe($parties[0]->organization);
    }
})->with([
    [1, ProcurementPartyRole::AuthorizedBody, '01013000002', '30253020551025301001'],
    [2, ProcurementPartyRole::AuthorizedBody, '01523000102', '35509007093550901001'],
    [3, ProcurementPartyRole::AuthorizedInstitution, '08466000011', '34806000980480601001'],
    [8, ProcurementPartyRole::Customer, '08696000222', '37431004348745501001'],
]);

test('историческая усечённая fixture без организаций не разрешает удаление ролей', function () {
    $html = partiesFixtureHtml();
    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html);

    expect($parties)->toBe([])
        ->and($parser->authorizesReconciliation($html, $parties))->toBeFalse();
});

test('parse разбирает известные роли организаций и пропускает неизвестную роль-строку без падения (совместимая табличная раскладка)', function () {
    $html = <<<'HTML'
    <html><body>
    <section class="organizations-block">
      <table class="parties-table">
        <tbody>
          <tr>
            <td>Заказчик</td>
            <td>Администрация городского округа</td>
            <td>5001234567</td>
            <td>500101001</td>
            <td>1025001234567</td>
            <td>Иванов Иван Иванович</td>
            <td>Главный специалист</td>
          </tr>
          <tr>
            <td>Размещающая организация</td>
            <td>Администрация городского округа</td>
            <td>5001234567</td>
            <td>500101001</td>
            <td>1025001234567</td>
            <td></td>
            <td></td>
          </tr>
          <tr>
            <td>Оператор торгов</td>
            <td>ООО «Неизвестный оператор»</td>
            <td>7700000000</td>
            <td>770001001</td>
            <td>1027700000000</td>
            <td></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </section>
    </body></html>
    HTML;

    $parties = app(PartiesParser::class)->parse($html);

    // 3 строки в разметке, 1 с неизвестной ролью «Оператор торгов» — пропущена.
    expect($parties)->toHaveCount(2);

    expect($parties[0]->role)->toBe(ProcurementPartyRole::Customer)
        ->and($parties[0]->organization->fullName)->toBe('Администрация городского округа')
        ->and($parties[0]->organization->inn)->toBe('5001234567')
        ->and($parties[0]->contactPersonName)->toBe('Иванов Иван Иванович')
        ->and($parties[0]->contactPersonPosition)->toBe('Главный специалист');

    expect($parties[1]->role)->toBe(ProcurementPartyRole::Placer)
        ->and($parties[1]->contactPersonName)->toBeNull();

    expect(collect($parties)->pluck('organization.fullName'))->not->toContain('ООО «Неизвестный оператор»');
});

test('parse возвращает пустой список при отсутствии блока organizations-block', function () {
    $parties = app(PartiesParser::class)->parse('<html><body>пусто</body></html>');

    expect($parties)->toBe([]);
});

test('authorizesReconciliation разрешает пустой complete контейнер и запрещает отсутствующий', function () {
    $parser = app(PartiesParser::class);
    $completeHtml = '<section class="organizations-block"><table class="parties-table"><tbody></tbody></table></section>';

    expect($parser->authorizesReconciliation(html: $completeHtml, parties: []))->toBeTrue()
        ->and($parser->authorizesReconciliation(html: '<html><body>пусто</body></html>', parties: []))->toBeFalse();
});

test('неполные и противоречивые стороны запрещают reconciliation', function (string $needle, string $replacement) {
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-1.html'));
    $html = str_replace($needle, $replacement, $html);
    $parser = app(PartiesParser::class);

    expect($parser->authorizesReconciliation($html, $parser->parse($html)))->toBeFalse();
})->with([
    'потерян код организации' => ['organizationCode=01013000002', 'other=01013000002'],
    'неизвестная роль' => ['Уполномоченный орган', 'Новая неизвестная роль'],
    'повреждён ИКЗ' => ['253025302055102530100100040014321244', 'broken'],
    'потерян блок требований' => ['class="row lots-block"', 'class="lost-block"'],
    'неизвестный заказчик в truInfo' => ['class="tableBlock__col tableBlock__col_header py-0"', 'class="tableBlock__col tableBlock__col_header py-0">Другой заказчик</td><td'],
]);

test('совместная закупка даёт каждого заказчика по собственному ИКЗ', function () {
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-1.html'));
    $dom = new Crawler($html);
    $block = $dom->filter('.lots-block')->outerHtml();
    $name = $dom->filter('.lots-block-title .collapse__title_text')->text();
    $second = str_replace(
        [$name, '253025302055102530100100040014321244'],
        ['Требования заказчика «Второй заказчик»', '253550900709355090100100120124339244'],
        $block,
    );
    // DOM serialization encodes quotes/entities, so replace the title node explicitly.
    $secondDom = new Crawler($second);
    $secondDom->filter('.collapse__title_text')->getNode(0)->textContent = 'Требования заказчика «Второй заказчик»';
    $html = str_replace('</body>', $secondDom->filter('.lots-block')->outerHtml().'</body>', $html);
    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html);
    $customers = collect($parties)->where('role', ProcurementPartyRole::Customer);

    expect($parser->authorizesReconciliation($html, $parties))->toBeTrue()
        ->and($customers)->toHaveCount(2)
        ->and($customers->map(fn ($party) => collect($party->organization->identifiers)->firstWhere('type', 'customer_code')->value)->values()->all())->toBe(['30253020551025301001', '35509007093550901001']);
});

test('gate не принимает произвольное подмножество разобранных ролей', function () {
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-1.html'));
    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html);

    expect($parser->authorizesReconciliation($html, array_slice($parties, 0, 1)))->toBeFalse();
});

test('региональный оператор капремонта по ПП РФ 615 без связи с планом-графиком разбирается как полный заказчик', function () {
    $role615 = 'Специализированная некоммерческая организация, которая осуществляет деятельность, направленную на обеспечение проведения капитального ремонта общего имущества в многоквартирных домах в соответствии с Постановлением № 615 (региональный оператор)';
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-8.html'));
    $html = str_replace('Заказчик', $role615, $html);
    $html = preg_replace(
        '#<span class="section__title">Идентификационный код закупки \(ИКЗ\)</span>.*?</span>#su',
        '<span class="section__title">Сведения о связи с позицией плана-графика</span><span class="section__info">Связь с позицией плана-графика не установлена</span>',
        $html,
    );

    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html, strict: true);

    expect($parser->authorizesReconciliation($html, $parties, strict: true))->toBeTrue()
        ->and($parties)->toHaveCount(2)
        ->and($parties[0]->role)->toBe(ProcurementPartyRole::Placer)
        ->and($parties[1]->role)->toBe(ProcurementPartyRole::Customer)
        ->and($parties[1]->organization->identifiers)->toHaveCount(1)
        ->and($parties[1]->organization->identifiers[0]->type)->toBe('organization_code')
        ->and($parties[1]->organization->identifiers[0]->value)->toBe('08696000222');
});

test('организация по части 6 статьи 15 44-ФЗ разбирается как уполномоченное учреждение с отдельным заказчиком лота', function () {
    $roleCh6St15 = 'Организация, осуществляющая полномочия заказчика на осуществление закупок на основании соглашения в соответствии с частью 6 статьи 15 Федерального закона № 44-ФЗ';
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-3.html'));
    $html = str_replace('Уполномоченное учреждение', $roleCh6St15, $html);

    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html, strict: true);

    expect($parser->authorizesReconciliation($html, $parties, strict: true))->toBeTrue()
        ->and($parties)->toHaveCount(3)
        ->and($parties[0]->role)->toBe(ProcurementPartyRole::Placer)
        ->and($parties[1]->role)->toBe(ProcurementPartyRole::AuthorizedInstitution)
        ->and($parties[2]->role)->toBe(ProcurementPartyRole::Customer);
});
