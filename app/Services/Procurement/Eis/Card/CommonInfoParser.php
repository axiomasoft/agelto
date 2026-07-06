<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis\Card;

use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `common-info` карточки закупки ЕИС (`research/eis-card-recon.md` §3–§5) в
 * {@see CommonInfoData}. Только HTML → DTO, без записи в БД (это `CommonInfoProcessor`).
 * Устойчив к отсутствию отдельных блоков вёрстки — блок отсутствует → соответствующее
 * поле/список остаётся `null`/пустым, парсер не падает.
 */
final readonly class CommonInfoParser
{
    private const int IKZ_LENGTH = 36;

    public function parse(string $html): CommonInfoData
    {
        $crawler = new Crawler($html);

        $ikz = $this->parseIkz($crawler);
        $etp = $this->parseEtp($crawler);
        $contactPerson = $this->parseContactPerson($crawler);

        return new CommonInfoData(
            ikzYear: $ikz['ikz_year'] ?? null,
            ikzCustomerCode: $ikz['ikz_customer_code'] ?? null,
            ikzPlanNumber: $ikz['ikz_plan_number'] ?? null,
            ikzPlangraphNumber: $ikz['ikz_plangraph_number'] ?? null,
            ikzOkpdClass: $ikz['ikz_okpd_class'] ?? null,
            ikzOkpdSubclass: $ikz['ikz_okpd_subclass'] ?? null,
            ikzOkpdGroup: $ikz['ikz_okpd_group'] ?? null,
            ikzKvr: $ikz['ikz_kvr'] ?? null,
            etpName: $etp['name'] ?? null,
            etpUrl: $etp['url'] ?? null,
            contactPersonName: $contactPerson['name'] ?? null,
            contactPersonEmail: $contactPerson['email'] ?? null,
            financingRows: $this->parseFinancingRows($crawler),
            items: $this->parseItems($crawler),
        );
    }

    /**
     * @return array{ikz_year?: int, ikz_customer_code?: string, ikz_plan_number?: string, ikz_plangraph_number?: string, ikz_okpd_class?: string, ikz_okpd_subclass?: string, ikz_okpd_group?: string, ikz_kvr?: string}
     */
    private function parseIkz(Crawler $crawler): array
    {
        $ikz = $this->readDefinitionValue(crawler: $crawler, section: 'section.price-info-block', term: 'ИКЗ');

        if ($ikz === null || strlen($ikz) !== self::IKZ_LENGTH) {
            return [];
        }

        return [
            'ikz_year' => (int) substr($ikz, 0, 2),
            'ikz_customer_code' => substr($ikz, 2, 20),
            'ikz_plan_number' => substr($ikz, 22, 4),
            'ikz_plangraph_number' => substr($ikz, 26, 3),
            'ikz_okpd_class' => substr($ikz, 29, 2),
            'ikz_okpd_subclass' => substr($ikz, 31, 1),
            'ikz_okpd_group' => substr($ikz, 32, 1),
            'ikz_kvr' => substr($ikz, 33, 3),
        ];
    }

    /**
     * @return array{name?: string, url?: string}
     */
    private function parseEtp(Crawler $crawler): array
    {
        try {
            $node = $crawler
                ->filter('section.common-info-block dt')
                ->reduce(fn (Crawler $dt): bool => trim($dt->text()) === 'Наименование электронной площадки');

            if ($node->count() === 0) {
                return [];
            }

            $link = $node->nextAll()->filter('dd')->first()->filter('a');

            if ($link->count() === 0) {
                return [];
            }

            return [
                'name' => trim($link->text()),
                'url' => $link->attr('href'),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{name?: string, email?: string}
     */
    private function parseContactPerson(Crawler $crawler): array
    {
        try {
            if ($crawler->filter('section.contact-info-block')->count() === 0) {
                return [];
            }

            $name = $this->readDefinitionValue(crawler: $crawler, section: 'section.contact-info-block', term: 'Ответственное должностное лицо');
            $email = $this->readDefinitionValue(crawler: $crawler, section: 'section.contact-info-block', term: 'Email');

            return array_filter([
                'name' => $name,
                'email' => $email,
            ], static fn (?string $value): bool => $value !== null);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<FinancingRowData>
     */
    private function parseFinancingRows(Crawler $crawler): array
    {
        try {
            $table = $crawler->filter('section.financing-info-block table.financing-table');

            if ($table->count() === 0) {
                return [];
            }

            $rows = [];

            $table->filter('tbody tr')->each(function (Crawler $tr) use (&$rows): void {
                $cells = $tr->filter('td');

                if ($cells->count() < 2) {
                    return;
                }

                $yearCell = trim($cells->eq(0)->text());

                // Итоговая строка «Всего» — производная, в prc_financing не пишется.
                if (! ctype_digit($yearCell)) {
                    return;
                }

                $rows[] = new FinancingRowData(
                    fiscalYear: (int) $yearCell,
                    amount: $this->normalizeAmount(trim($cells->eq(1)->text())),
                );
            });

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Инвариант идемпотентности: `positionNumber` — ключ апдейта в `upsertItem()`
     * (`updateOrCreate` по `(procurement_id, position_number)`), поэтому он обязан быть
     * стабилен между повторными прогонами одной и той же карточки. Основной путь — реальный
     * `№` из вёрстки (не зависит от порядка/фильтрации строк). Fallback `$index + 1`
     * применяется ТОЛЬКО к строке с пустым/нечисловым `№` — это брак вёрстки источника,
     * а не штатный случай; `$index` в each() — это порядковый номер строки внутри исходной
     * `tbody tr` (до фильтрации по `$cells->count() < 7`, которая просто `return`-ит и не
     * добавляет позицию в `$items`, не сдвигая индексацию последующих строк), то есть
     * fallback-номер строки НЕ зависит от того, сколько позиций реально распознано —
     * только от её положения в HTML-таблице. Если у площадки когда-нибудь появится
     * стабильная перестановка строк без изменения `№` — потребуется отдельный устойчивый
     * идентификатор позиции (например, по совокупности name+characteristics), это вне
     * объёма текущего среза.
     *
     * @return list<PurchaseItemRowData>
     */
    private function parseItems(Crawler $crawler): array
    {
        try {
            $table = $crawler->filter('section.purchase-items-block table.items-table');

            if ($table->count() === 0) {
                return [];
            }

            $items = [];

            $table->filter('tbody tr')->each(function (Crawler $tr, int $index) use (&$items): void {
                $cells = $tr->filter('td');

                if ($cells->count() < 7) {
                    return;
                }

                $positionNumber = trim($cells->eq(0)->text());
                [$okpd2Code, $ktruCode] = $this->splitClassifierCode(trim($cells->eq(1)->text()));

                $items[] = new PurchaseItemRowData(
                    positionNumber: $positionNumber !== '' && ctype_digit($positionNumber) ? (int) $positionNumber : $index + 1,
                    name: trim($cells->eq(2)->text()),
                    characteristics: null,
                    classifierScheme: $this->classifierScheme(okpd2Code: $okpd2Code, ktruCode: $ktruCode),
                    classifierCodeOkpd2: $okpd2Code,
                    classifierCodeKtru: $ktruCode,
                    unit: trim($cells->eq(3)->text()) ?: null,
                    quantity: $this->normalizeAmount(trim($cells->eq(4)->text())),
                    unitPrice: $this->normalizeAmount(trim($cells->eq(5)->text())),
                    totalCost: $this->normalizeAmount(trim($cells->eq(6)->text())),
                );
            });

            return $items;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * `classifier_scheme` — `NOT NULL text` (`2026_07_04_100015_create_prc_items_table.php`),
     * поэтому при отсутствии обоих кодов пишем явное `unknown`, а не вводящее в заблуждение
     * `ktru` без реального КТРУ-кода.
     */
    private function classifierScheme(?string $okpd2Code, ?string $ktruCode): string
    {
        return match (true) {
            $okpd2Code !== null => 'okpd2',
            $ktruCode !== null => 'ktru',
            default => 'unknown',
        };
    }

    /**
     * @return array{0: ?string, 1: ?string} [ОКПД2, КТРУ]
     */
    private function splitClassifierCode(string $rawCode): array
    {
        if ($rawCode === '') {
            return [null, null];
        }

        if (! str_contains($rawCode, '/')) {
            return [$rawCode, null];
        }

        [$okpd2, $ktru] = array_map('trim', explode('/', $rawCode, limit: 2));

        return [$okpd2 !== '' ? $okpd2 : null, $ktru !== '' ? $ktru : null];
    }

    private function normalizeAmount(string $rawAmount): ?string
    {
        if ($rawAmount === '') {
            return null;
        }

        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', $rawAmount);
        $normalized = str_replace(',', '.', (string) $normalized);

        return is_numeric($normalized) ? $normalized : null;
    }

    private function readDefinitionValue(Crawler $crawler, string $section, string $term): ?string
    {
        try {
            $node = $crawler
                ->filter("{$section} dt")
                ->reduce(fn (Crawler $dt): bool => trim($dt->text()) === $term);

            if ($node->count() === 0) {
                return null;
            }

            $value = trim($node->nextAll()->filter('dd')->first()->text());

            return $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
