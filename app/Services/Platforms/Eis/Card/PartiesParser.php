<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\DriftCollector;
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
    private const array ROLE_MAP = EisDefaultParsingRules::PARTY_ROLE_MAP;

    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(
        private ?DetailRuleSetDto $rules = null,
        ?DomExtractor $dom = null,
        private ?DriftCollector $drift = null,
    ) {}

    /** @return list<PartyData> */
    public function parse(string $html, bool $strict = false): array
    {
        try {
            $snapshot = $this->snapshot($html);
            if (! $snapshot['complete']) {
                $this->drift?->record(new DriftSignal('parties_incomplete', StageCode::Detail, 'section.organizations-block'));
                CardDomHelper::logWarning('Вкладка common-info не содержит подтверждённый полный набор сторон.');
            }

            return $snapshot['parties'];
        } catch (\UnexpectedValueException $exception) {
            CardDomHelper::logWarning($exception->getMessage());

            return [];
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
        } catch (\UnexpectedValueException) {
            return false;
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
        $rules = $this->rules ?? EisDefaultParsingRules::detail();
        $crawler = new Crawler($html);
        $table = $this->firstMatching($crawler, $rules->parties?->customerSectionSelectors, 'section.organizations-block table.parties-table');

        if ($table->count() > 0 && ($table->nodeName() === 'table' || $table->filter('tbody tr')->count() > 0)) {
            $rows = $table->filter('tbody tr');
            $parties = array_values(array_filter($rows->each(fn (Crawler $row): ?PartyData => $this->parseRow($row))));

            return ['parties' => $parties, 'complete' => count($parties) === $rows->count()];
        }

        return $this->parseReal($crawler);
    }

    private function firstMatching(Crawler $crawler, ?SelectorChain $chain, string $defaultSelector): Crawler
    {
        if ($chain !== null) {
            foreach ($chain->selectors as $selector) {
                $matched = $crawler->filter($selector->selector);
                if ($matched->count() > 0) {
                    return $matched;
                }
            }

            return new Crawler;
        }

        return $crawler->filter($defaultSelector);
    }

    /** @return array{parties: list<PartyData>, complete: bool} */
    private function parseReal(Crawler $crawler): array
    {
        $placement = $crawler->filter('section.blockInfo__section, p.blockInfo__section')->reduce(function (Crawler $section): bool {
            return ($this->definition($section, 'Размещение осуществляет') !== null
                || $section->filter('.section__title')->reduce(fn (Crawler $label): bool => $this->normalize($label->text()) === 'Размещение осуществляет')->count() === 1)
                && $section->filter('a[href*="/epz/organization/view/info.html?"]')->count() > 0;
        });
        if ($placement->count() === 0) {
            return $this->customerWithoutOrganizationLink($crawler);
        }

        ['name' => $name, 'roleText' => $roleText, 'role' => $role, 'code' => $code] = $this->parsePlacementSection($crawler, $placement);
        $complete = $role !== null;
        $customers = $this->collectLotCustomers($crawler, $complete);

        $allCodes = $this->customerCodes($crawler);
        $unlinkedCustomerPlan = $role === ProcurementPartyRole::Customer
            && $allCodes === []
            && $customers === []
            && $this->definition($crawler, 'Сведения о связи с позицией плана-графика') === 'Связь с позицией плана-графика не установлена';
        $this->requireComplete($allCodes !== [] || $unlinkedCustomerPlan);

        if ($role === ProcurementPartyRole::Customer && ! $unlinkedCustomerPlan) {
            $this->requireComplete(count($allCodes) === 1);
            $customerCode = $allCodes[0];
            $this->requireComplete($customers === [] || $customers === [$customerCode => $name]);
            $customers[$customerCode] = $name;
        }

        $customerCodes = array_map('strval', array_keys($customers));
        sort($customerCodes);
        sort($allCodes);
        $complete = $complete && $customerCodes === $allCodes;

        $this->verifyTruInfoCustomers($crawler, $unlinkedCustomerPlan ? [$name] : $customers, $complete);
        $contact = $this->parseContactBlock($crawler, $name, $roleText);

        return [
            'parties' => $this->buildRealParties($name, $code, $role, $allCodes, $customers, $contact),
            'complete' => $complete,
        ];
    }

    /** @return array{name: string, roleText: string, role: ?ProcurementPartyRole, code: string} */
    private function parsePlacementSection(Crawler $crawler, Crawler $placement): array
    {
        $link = $placement->filter('a[href*="/epz/organization/view/info.html?"]');
        $this->requireComplete($link->count() === 1);
        $name = $this->normalize($link->text()) ?: $this->placementName($crawler) ?? '';
        $roleText = $this->normalize(str_replace($name, '', $this->definition($placement, 'Размещение осуществляет')
            ?? $this->definition($crawler, 'Размещение осуществляет') ?? ''));
        $role = self::ROLE_MAP[$roleText] ?? null;
        $this->requireComplete($name !== '');
        parse_str(parse_url($link->attr('href') ?? '', PHP_URL_QUERY) ?: '', $query);
        $code = $query['organizationCode'] ?? null;
        $this->requireComplete(is_string($code) && preg_match('/^\d+$/D', $code) === 1);

        return ['name' => $name, 'roleText' => $roleText, 'role' => $role, 'code' => $code];
    }

    /** @return array<int|string, string> */
    private function collectLotCustomers(Crawler $crawler, bool &$complete): array
    {
        $customers = [];
        $crawler->filter('.lots-block')->each(function (Crawler $block) use (&$customers, &$complete): void {
            $title = $block->filter('.lots-block-title .collapse__title_text');
            $this->requireComplete($title->count() === 1);
            $text = $this->normalize($title->text());
            if (preg_match('/^Требования заказчика\s*(?:«(.+)»|"(.+)"|“(.+)”)$/uD', $text, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
                $complete = false;

                return;
            }
            $codes = $this->customerCodes($block);
            $this->requireComplete(count($codes) === 1);
            $customerCode = $codes[0];
            $customerName = trim($match[1] ?? $match[2] ?? $match[3] ?? '');
            $this->requireComplete($customerName !== '' && (! isset($customers[$customerCode]) || $customers[$customerCode] === $customerName));
            $customers[$customerCode] = $customerName;
        });

        return $customers;
    }

    /** @param array<int|string, string> $allowedCustomerNames */
    private function verifyTruInfoCustomers(Crawler $crawler, array $allowedCustomerNames, bool &$complete): void
    {
        // truInfo is only a cross-check. Its names are not global organization identities.
        $crawler->filter('tr[class*="truInfo_"] > td.tableBlock__col_header')->each(function (Crawler $cell) use ($allowedCustomerNames, &$complete): void {
            $complete = $complete && in_array($this->normalize($cell->text()), $allowedCustomerNames, true);
        });
    }

    private function parseContactBlock(Crawler $crawler, string $name, string $roleText): Crawler
    {
        $contactBlocks = $crawler->filter('.row.blockInfo')->reduce(fn (Crawler $block): bool => $block->filter('h2.blockInfo__title')->count() === 1
            && $this->normalize($block->filter('h2.blockInfo__title')->text()) === 'Контактная информация'
        );
        $this->requireComplete($contactBlocks->count() === 1);
        $contact = $contactBlocks->first();
        $this->requireComplete($this->definition($contact, 'Организация, осуществляющая размещение') === $name);
        $this->requireComplete($this->definition($contact, 'Размещение осуществляет') === $roleText);

        return $contact;
    }

    /**
     * @param  list<string>  $allCodes
     * @param  array<int|string, string>  $customers
     * @return list<PartyData>
     */
    private function buildRealParties(
        string $name,
        string $code,
        ?ProcurementPartyRole $role,
        array $allCodes,
        array $customers,
        Crawler $contact,
    ): array {
        $organization = new OrganizationData(
            fullName: $name,
            addressRaw: $this->definition($contact, 'Место нахождения'),
            phone: $this->definition($contact, 'Номер контактного телефона'),
            email: $this->definition($contact, 'Адрес электронной почты'),
            identifiers: $role === ProcurementPartyRole::Customer && $allCodes !== []
                ? [new OrganizationIdentifierData(platformCode: 'eis', type: 'organization_code', value: $code),
                    new OrganizationIdentifierData(platformCode: 'eis', type: 'customer_code', value: $allCodes[0])]
                : [new OrganizationIdentifierData(platformCode: 'eis', type: 'organization_code', value: $code)],
        );
        $person = $this->definition($contact, 'Ответственное должностное лицо');
        $parties = [new PartyData(role: ProcurementPartyRole::Placer, organization: $organization, contactPersonName: $person)];
        if ($role !== null && $role !== ProcurementPartyRole::Placer) {
            $parties[] = new PartyData(role: $role, organization: $organization, contactPersonName: $person);
        }

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

    /** @return array{parties: list<PartyData>, complete: bool} */
    private function customerWithoutOrganizationLink(Crawler $crawler): array
    {
        $name = $this->placementName($crawler);
        $codes = $this->customerCodes($crawler);
        if ($name === null || count($codes) !== 1 || $this->definition($crawler, 'Размещение осуществляет') !== 'Заказчик') {
            return ['parties' => [], 'complete' => false];
        }

        $organization = new OrganizationData(fullName: $name, identifiers: [
            new OrganizationIdentifierData(platformCode: 'eis', type: 'customer_code', value: $codes[0]),
        ]);

        return ['parties' => [
            new PartyData(role: ProcurementPartyRole::Placer, organization: $organization),
            new PartyData(role: ProcurementPartyRole::Customer, organization: $organization),
        ], 'complete' => true];
    }

    private function placementName(Crawler $crawler): ?string
    {
        return $this->definition($crawler, 'Организация, осуществляющая размещение')
            ?? CardDomHelper::readDefinitionValue(crawler: $crawler, term: 'Наименование заказчика')
            ?? CardDomHelper::readDefinitionValue(crawler: $crawler, term: 'Заказчик');
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
        $sections = $crawler->filter('section.blockInfo__section, p.blockInfo__section');

        // filter() searches descendants; include a section passed as the root itself.
        if ($crawler->count() === 1 && in_array($crawler->nodeName(), ['section', 'p'], true)) {
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

        return count(array_unique($values)) === 1 && $values[0] !== '' ? $values[0] : null;
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

        $name = $this->normalize($cells->eq(1)->text());
        $inn = CardDomHelper::extractInn($cells->eq(2)->text());
        $ogrn = $this->nullableCell($cells, 4);
        if ($name === '' || ($inn === null && $ogrn === null)) {
            return null;
        }

        return new PartyData(
            role: $role,
            organization: new OrganizationData(
                fullName: $name,
                inn: $inn,
                kpp: $this->nullableCell($cells, 3),
                ogrn: $ogrn,
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
