<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Enums\Organization\ProcurementPartyRole;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор организаций и их ролей на вкладке `common-info` карточки закупки ЕИС
 * (`research/eis-card-recon.md` §3) в список {@see PartyData}. Только HTML → DTO, без
 * записи в БД (это `OrganizationPartiesProcessor`) и без entity resolution (это
 * `OrganizationResolver`) — здесь организация ровно как упомянута в источнике.
 */
final readonly class PartiesParser
{
    /**
     * @var array<string, ProcurementPartyRole>
     */
    private const array ROLE_MAP = [
        'Заказчик' => ProcurementPartyRole::Customer,
        'Размещающая организация' => ProcurementPartyRole::Placer,
        'Уполномоченный орган' => ProcurementPartyRole::AuthorizedBody,
        'Уполномоченное учреждение' => ProcurementPartyRole::AuthorizedInstitution,
        'Специализированная организация' => ProcurementPartyRole::SpecializedOrganization,
        'Оператор электронной площадки' => ProcurementPartyRole::PlatformOperator,
    ];

    /** @return list<PartyData> */
    public function parse(string $html, bool $strict = false): array
    {
        try {
            return $this->snapshot($html)['parties'];
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            return [];
        }
    }

    /** @param list<PartyData> $parties */
    public function authorizesReconciliation(string $html, array $parties, bool $strict = false): bool
    {
        try {
            $snapshot = $this->snapshot($html);

            return $snapshot['complete'] && $snapshot['parties'] == $parties;
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            CardDomHelper::logWarning('Вкладка common-info не содержит подтверждённый полный набор сторон.');

            return false;
        }
    }

    /** @return array{parties: list<PartyData>, complete: bool} */
    private function snapshot(string $html): array
    {
        $crawler = new Crawler($html);
        $table = $crawler->filter('section.organizations-block table.parties-table');

        if ($table->count() > 0) {
            $rows = $table->filter('tbody tr');
            $parties = array_values(array_filter($rows->each(fn (Crawler $row): ?PartyData => $this->parseRow($row))));

            return ['parties' => $parties, 'complete' => count($parties) === $rows->count()];
        }

        return ['parties' => $this->parseReal($crawler), 'complete' => true];
    }

    /** @return list<PartyData> */
    private function parseReal(Crawler $crawler): array
    {
        $placement = $crawler->filter('section.blockInfo__section')->reduce(function (Crawler $section): bool {
            return $this->definition($section, 'Размещение осуществляет') !== null
                && $section->filter('a[href*="/epz/organization/view/info.html?"]')->count() > 0;
        });
        $this->requireComplete($placement->count() === 1);
        $link = $placement->filter('a[href*="/epz/organization/view/info.html?"]');
        $this->requireComplete($link->count() === 1);
        $name = $this->normalize($link->text());
        $roleText = $this->normalize(str_replace($name, '', $this->definition($placement, 'Размещение осуществляет') ?? ''));
        $role = self::ROLE_MAP[$roleText] ?? null;
        $this->requireComplete($name !== '' && $role !== null);
        parse_str(parse_url($link->attr('href') ?? '', PHP_URL_QUERY) ?: '', $query);
        $code = $query['organizationCode'] ?? null;
        $this->requireComplete(is_string($code) && preg_match('/^\d+$/D', $code) === 1);

        $customers = [];
        $crawler->filter('.lots-block')->each(function (Crawler $block) use (&$customers): void {
            $title = $block->filter('.lots-block-title .collapse__title_text');
            $this->requireComplete($title->count() === 1);
            $text = $this->normalize($title->text());
            $this->requireComplete(preg_match('/^Требования заказчика\s*«(.+)»$/uD', $text, $match) === 1);
            $codes = $this->customerCodes($block);
            $this->requireComplete(count($codes) === 1);
            $customerCode = $codes[0];
            $customerName = trim($match[1]);
            $this->requireComplete($customerName !== '' && (! isset($customers[$customerCode]) || $customers[$customerCode] === $customerName));
            $customers[$customerCode] = $customerName;
        });

        $allCodes = $this->customerCodes($crawler);
        $this->requireComplete($allCodes !== []);

        if ($role === ProcurementPartyRole::Customer) {
            $this->requireComplete(count($allCodes) === 1);
            $customerCode = $allCodes[0];
            $this->requireComplete($customers === [] || $customers === [$customerCode => $name]);
            $customers[$customerCode] = $name;
        }

        $customerCodes = array_map('strval', array_keys($customers));
        sort($customerCodes);
        sort($allCodes);
        $this->requireComplete($customerCodes === $allCodes);

        // truInfo is only a cross-check. Its names are not global organization identities.
        $crawler->filter('tr[class*="truInfo_"] > td.tableBlock__col_header')->each(function (Crawler $cell) use ($customers): void {
            $this->requireComplete(in_array($this->normalize($cell->text()), $customers, true));
        });

        $contactBlocks = $crawler->filter('.row.blockInfo')->reduce(fn (Crawler $block): bool => $block->filter('h2.blockInfo__title')->count() === 1
            && $this->normalize($block->filter('h2.blockInfo__title')->text()) === 'Контактная информация'
        );
        $this->requireComplete($contactBlocks->count() === 1);
        $contact = $contactBlocks->first();
        $this->requireComplete($this->definition($contact, 'Организация, осуществляющая размещение') === $name);
        $this->requireComplete($this->definition($contact, 'Размещение осуществляет') === $roleText);

        $organization = new OrganizationData(
            fullName: $name,
            addressRaw: $this->definition($contact, 'Место нахождения'),
            phone: $this->definition($contact, 'Номер контактного телефона'),
            email: $this->definition($contact, 'Адрес электронной почты'),
            identifiers: $role === ProcurementPartyRole::Customer
                ? [new OrganizationIdentifierData(platformCode: 'eis', type: 'organization_code', value: $code),
                    new OrganizationIdentifierData(platformCode: 'eis', type: 'customer_code', value: $allCodes[0])]
                : [new OrganizationIdentifierData(platformCode: 'eis', type: 'organization_code', value: $code)],
        );
        $person = $this->definition($contact, 'Ответственное должностное лицо');
        $parties = [new PartyData(role: ProcurementPartyRole::Placer, organization: $organization, contactPersonName: $person)];
        $parties[] = new PartyData(role: $role, organization: $organization, contactPersonName: $person);

        if ($role !== ProcurementPartyRole::Customer) {
            foreach ($customers as $customerCode => $customerName) {
                $parties[] = new PartyData(
                    role: ProcurementPartyRole::Customer,
                    organization: new OrganizationData(fullName: $customerName, identifiers: [
                        new OrganizationIdentifierData(platformCode: 'eis', type: 'customer_code', value: (string) $customerCode),
                    ]),
                );
            }
        }

        return $parties;
    }

    /** @return list<string> */
    private function customerCodes(Crawler $crawler): array
    {
        $codes = [];
        $crawler->filter('section.blockInfo__section')->each(function (Crawler $section) use (&$codes): void {
            $ikz = $this->definition($section, 'Идентификационный код закупки (ИКЗ)');

            if ($ikz === null) {
                return;
            }

            $this->requireComplete(preg_match('/^\d{36}$/D', $ikz) === 1);
            $code = substr($ikz, 2, 20);
            $this->requireComplete($code !== str_repeat('0', 20));
            $codes[] = $code;
        });

        return array_values(array_unique($codes));
    }

    private function definition(Crawler $crawler, string $title): ?string
    {
        $sections = $crawler->filter('section.blockInfo__section');

        // filter() searches descendants; include a section passed as the root itself.
        if ($crawler->count() === 1 && $crawler->nodeName() === 'section') {
            $sections = $crawler;
        }

        $values = [];
        $sections->each(function (Crawler $section) use ($title, &$values): void {
            $label = $section->filter('.section__title');
            $value = $section->filter('.section__info');

            if ($label->count() === 1 && $this->normalize($label->text()) === $title && $value->count() === 1) {
                $values[] = $this->normalize($value->text());
            }
        });

        return count($values) === 1 && $values[0] !== '' ? $values[0] : null;
    }

    private function normalize(string $value): string
    {
        return CardDomHelper::normalizeWhitespace($value);
    }

    /** @phpstan-assert true $condition */
    private function requireComplete(bool $condition): void
    {
        if (! $condition) {
            throw new \UnexpectedValueException('Неполная или противоречивая проекция сторон common-info.');
        }
    }

    private function parseRow(Crawler $tr): ?PartyData
    {
        $cells = $tr->filter('td');

        if ($cells->count() < 5) {
            return null;
        }

        $roleText = trim($cells->eq(0)->text());
        $role = self::ROLE_MAP[$roleText] ?? null;

        if ($role === null) {
            CardDomHelper::logWarning('Неизвестная роль организации на вкладке common-info пропущена', ['role' => $roleText]);

            return null;
        }

        return new PartyData(
            role: $role,
            organization: new OrganizationData(
                fullName: trim($cells->eq(1)->text()),
                inn: $this->nullableCell($cells, 2),
                kpp: $this->nullableCell($cells, 3),
                ogrn: $this->nullableCell($cells, 4),
            ),
            contactPersonName: $cells->count() > 5 ? $this->nullableCell($cells, 5) : null,
            contactPersonPosition: $cells->count() > 6 ? $this->nullableCell($cells, 6) : null,
        );
    }

    private function nullableCell(Crawler $cells, int $index): ?string
    {
        $value = trim($cells->eq($index)->text());

        return $value !== '' ? $value : null;
    }
}
