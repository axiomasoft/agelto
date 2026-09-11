<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis\Card;

use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Enums\Organization\ProcurementPartyRole;
use Illuminate\Support\Facades\Log;
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
        'Специализированная организация' => ProcurementPartyRole::SpecializedOrganization,
        'Оператор электронной площадки' => ProcurementPartyRole::PlatformOperator,
    ];

    /**
     * @return list<PartyData>
     */
    public function parse(string $html): array
    {
        try {
            $table = (new Crawler($html))->filter('section.organizations-block table.parties-table');

            if ($table->count() === 0) {
                return [];
            }

            $parties = [];

            $table->filter('tbody tr')->each(function (Crawler $tr) use (&$parties): void {
                $party = $this->parseRow($tr);

                if ($party !== null) {
                    $parties[] = $party;
                }
            });

            return $parties;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<PartyData> $parties */
    public function authorizesReconciliation(string $html, array $parties): bool
    {
        try {
            $table = (new Crawler($html))->filter('section.organizations-block table.parties-table');

            if ($table->count() === 0) {
                Log::warning('Вкладка common-info не содержит контейнер сторон; snapshot неполный');

                return false;
            }

            $rows = $table->filter('tbody tr')->count();

            if ($rows === count($parties)) {
                return true;
            }

            Log::warning('Вкладка common-info содержит нераспознанные стороны; snapshot неполный', [
                'rows' => $rows,
                'parsed_parties' => count($parties),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::warning('Проверка полноты сторон common-info провалилась; snapshot failed', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
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
            Log::warning('Неизвестная роль организации на вкладке common-info пропущена', ['role' => $roleText]);

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
