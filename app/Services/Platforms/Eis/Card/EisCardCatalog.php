<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Platforms\Eis\EisCardRoutes;

/** Pure adapter route catalog, shared by bootstrap and deterministic reference seeds. */
final readonly class EisCardCatalog
{
    public function routes(): EisCardRoutes
    {
        return new EisCardRoutes($this->cardUrlTemplates(), $this->procedureUrlTypes());
    }

    /** @return array<string, string> */
    public function procedureUrlTypes(): array
    {
        return [
            'Электронный аукцион' => 'ea20',
            'Электронный аукцион на проведение работ по строительству, реконструкции, кап. ремонту, '
            .'сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 Закона № 44-ФЗ' => 'ea20',
            'Запрос котировок в электронной форме' => 'zk20',
            'Запрос котировок в электронной форме на проведение работ по строительству, реконструкции, '
            .'кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 Закона '
            .'№ 44-ФЗ' => 'zk20',
            'Открытый конкурс в электронной форме' => 'ok20',
            'Открытый конкурс в электронной форме на проведение работ по строительству, реконструкции, '
            .'кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 Закона '
            .'№ 44-ФЗ' => 'ok20',
            'Закупка, осуществляемая в соответствии с частью 12 статьи 93 Закона № 44-ФЗ' => 'ezt20',
            'Электронный аукцион на оказание услуг или выполнение работ по капитальному ремонту общего '
            .'имущества в многоквартирном доме' => 'ea615',
            'Предварительный отбор квалифицированных подрядных организаций' => 'po615',
        ];
    }

    /**
     * Шаблоны URL детальной карточки по сегменту типа + вкладке — `{regNumber}` резолвит
     * `EisCardUrlBuilder::buildUrl()`. `supplier-results` у `ea615`/`po615` — явный маркер
     * `null` (D121): вкладка отвечает 404 у обоих типов «по природе» (разведка §`_observed`),
     * а не «справочник не заполнен» — `EisCardUrlBuilder` отличает эти два случая по
     * `array_key_exists`.
     *
     * @return array<string, array<string, ?string>>
     */
    public function cardUrlTemplates(): array
    {
        $tabs = ['common-info', 'documents', 'event-journal', 'supplier-results'];
        $withoutSupplierResults = ['common-info', 'documents', 'event-journal'];

        $templates = [];

        foreach (['ea20', 'zk20', 'ok20', 'ezt20'] as $type) {
            foreach ($tabs as $tab) {
                $templates[$type][$tab] = $this->cardUrl(type: $type, tab: $tab);
            }
        }

        foreach (['ea615', 'po615'] as $type) {
            foreach ($withoutSupplierResults as $tab) {
                $templates[$type][$tab] = $this->cardUrl(type: $type, tab: $tab);
            }

            $templates[$type]['supplier-results'] = null;
        }

        return $templates;
    }

    private function cardUrl(string $type, string $tab): string
    {
        return "https://zakupki.gov.ru/epz/order/notice/{$type}/view/{$tab}.html?regNumber={regNumber}";
    }
}
