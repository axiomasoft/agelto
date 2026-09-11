<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Services\Procurement\Eis\Card\PartiesParser;

function partiesFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/common-info.html'));
}

/**
 * Диагноз P4.14: `section.organizations-block table.parties-table` (селектор парсера, не
 * тронут — Implementation Rules item'а п. 2/5) даёт 0 совпадений на РЕАЛЬНОЙ вкладке
 * common-info (`findings/P4-card-layout.md` §3) — блока вообще нет на снятой странице.
 * [UNVERIFIED] в Pending Work item'а: правка селектора без фактуры запрещена.
 */
test('parse возвращает пустой список на реальной вкладке common-info — organizations-block отсутствует в снятой вёрстке', function () {
    $parties = app(PartiesParser::class)->parse(partiesFixtureHtml());

    expect($parties)->toBe([]);
});

test('parse разбирает известные роли организаций и пропускает неизвестную роль-строку без падения (синтетическая раскладка, парсер не правился)', function () {
    // Синтетический HTML под ТЕКУЩИЙ (нетронутый) селектор парсера — проверяет внутреннюю логику
    // ROLE_MAP/пропуска неизвестной роли, а не соответствие реальной вкладке (её у парсера
    // сейчас нет, см. тест выше и Pending Work item'а P4.14).
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
