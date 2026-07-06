<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis\Card;

use App\Dto\Crawler\Details\AwardData;
use App\Dto\Crawler\Details\BidData;
use App\Dto\Crawler\Details\OrganizationData;
use App\Dto\Crawler\Details\ProtocolData;
use App\Dto\Crawler\Details\SupplierResultsData;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\ProtocolType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `supplier-results` карточки закупки ЕИС (`research/eis-card-recon.md` §5,
 * `docs/specs/v2/02-crawling.md` §5.1) в {@see SupplierResultsData}. Только HTML → DTO,
 * без записи в БД (это `SupplierResultsProcessor`) и без entity resolution организаций
 * (это `OrganizationResolver`). Вкладка непуста только для завершающих этапов процедуры —
 * пустой/неопознанный HTML даёт пустой результат, не исключение.
 *
 * HTML-фикстура (`tests/Fixtures/Crawler/Card/supplier-results.html`) синтетическая: сырого
 * дампа этой вкладки в разведке нет, только текстовое описание полей (как для common-info/
 * documents/event-journal, P3.1/P3.4/P3.5/P3.6).
 */
final readonly class SupplierResultsParser
{
    /**
     * @var array<string, ProtocolType>
     */
    private const array PROTOCOL_TYPE_MAP = [
        'рассмотрения первых частей' => ProtocolType::FirstPartsReview,
        'рассмотрения вторых частей' => ProtocolType::SecondPartsReview,
        'проведения аукциона' => ProtocolType::Auction,
        'оценки' => ProtocolType::Evaluation,
        'подведения итогов' => ProtocolType::Summary,
        'о признании закупки несостоявшейся' => ProtocolType::Failed,
        'обоснования выбора единственного поставщика' => ProtocolType::SingleSupplierJustification,
    ];

    public function parse(string $html): SupplierResultsData
    {
        try {
            $root = (new Crawler($html))->filter('div.supplier-results');

            if ($root->count() === 0) {
                return new SupplierResultsData(protocol: null, bids: [], award: null);
            }

            $fields = $this->parseDefinitionList($root);
            $protocol = $this->parseProtocol($fields['Протокол'] ?? null);
            $winner = $this->parseWinner($fields['Победитель'] ?? null);
            $bids = $this->parseBids($root, $winner);
            $award = $this->buildAward($winner, $bids, $protocol);

            return new SupplierResultsData(protocol: $protocol, bids: $bids, award: $award);
        } catch (\Throwable $exception) {
            Log::warning('Разбор вкладки supplier-results провалился целиком, вкладка пропущена', [
                'exception' => $exception->getMessage(),
            ]);

            return new SupplierResultsData(protocol: null, bids: [], award: null);
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseDefinitionList(Crawler $root): array
    {
        $dl = $root->filter('dl');

        if ($dl->count() === 0) {
            return [];
        }

        $labels = $dl->filter('dt')->each(static fn (Crawler $dt): string => trim($dt->text()));
        $values = $dl->filter('dd')->each(static fn (Crawler $dd): string => trim($dd->text()));

        return array_combine($labels, $values) ?: [];
    }

    private function parseProtocol(?string $text): ?ProtocolData
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match('/^Протокол\s+(.+?)\s*№\s*(\S+)\s+от\s+(\d{2}\.\d{2}\.\d{4})/ui', $text, $matches) !== 1) {
            Log::warning('Строка протокола на вкладке supplier-results не распознана', ['text' => $text]);

            return null;
        }

        [, $typeText, $sourceNumber, $dateText] = $matches;

        $type = self::PROTOCOL_TYPE_MAP[mb_strtolower(trim($typeText))] ?? null;
        $date = $this->parseDate($dateText);

        return new ProtocolData(
            type: $type?->value ?? trim($typeText),
            sourceNumber: $sourceNumber,
            signedAt: $date,
            publishedAt: $date,
        );
    }

    private function parseWinner(?string $text): ?OrganizationData
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match('/^(.+?),\s*ИНН\s*(\d+)(?:,\s*ОГРН\s*(\d+))?/ui', $text, $matches) !== 1) {
            Log::warning('Строка победителя на вкладке supplier-results не распознана', ['text' => $text]);

            return null;
        }

        return new OrganizationData(
            fullName: trim($matches[1]),
            inn: $matches[2],
            ogrn: $matches[3] ?? null,
        );
    }

    /**
     * @return list<BidData>
     */
    private function parseBids(Crawler $root, ?OrganizationData $winner): array
    {
        $table = $root->filter('table.bids-table');

        if ($table->count() === 0) {
            return [];
        }

        $bids = [];

        $table->filter('tbody tr')->each(function (Crawler $tr) use ($winner, &$bids): void {
            $bid = $this->parseBidRow($tr, $winner);

            if ($bid !== null) {
                $bids[] = $bid;
            }
        });

        return $bids;
    }

    private function parseBidRow(Crawler $tr, ?OrganizationData $winner): ?BidData
    {
        $cells = $tr->filter('td');

        if ($cells->count() < 3) {
            return null;
        }

        $ordinalText = trim($cells->eq(0)->text());
        $name = trim($cells->eq(1)->text());
        $price = $this->parseAmount(trim($cells->eq(2)->text()));

        if ($name === '') {
            return null;
        }

        $isWinner = $winner !== null && mb_strtolower($winner->fullName) === mb_strtolower($name);

        // Обогащаем строку победителя ИНН/ОГРН из блока «Победитель», чтобы резолвер
        // не создал для победителя вторую org_organizations по одному имени без ИНН.
        // Остальные участники таблицы результатов не имеют в источнике ИНН/ОГРН — резолвить
        // их через OrganizationResolver нельзя: без надёжного идентификатора find() всегда
        // возвращает null, и второй (третий, …) безымянный участник любой закупки ловит
        // UniqueConstraintViolationException на org_organizations_inn_kpp_unique, а catch-
        // восстановление в OrganizationStoreRepository::create() молча склеивает его с ПЕРВОЙ
        // попавшейся организацией без ИНН — разные юрлица схлопываются в одну запись.
        // Поэтому для таких строк organization не резолвим вовсе: prc_bids.organization_id
        // остаётся null (см. SupplierResultsProcessor::storeBid).
        $organization = $isWinner ? $winner : null;

        return new BidData(
            organization: $organization,
            participantOrdinal: is_numeric($ordinalText) ? (int) $ordinalText : null,
            price: $price,
            status: $isWinner ? BidStatus::Winner : BidStatus::Admitted,
        );
    }

    /**
     * @param  list<BidData>  $bids
     */
    private function buildAward(?OrganizationData $winner, array $bids, ?ProtocolData $protocol): ?AwardData
    {
        if ($winner === null) {
            return null;
        }

        $winningBid = collect($bids)->first(static fn (BidData $bid): bool => $bid->status === BidStatus::Winner);

        return new AwardData(
            winner: $winner,
            finalPrice: $winningBid?->price,
            status: AwardStatus::Awarded,
            awardedAt: $protocol?->publishedAt ?? $protocol?->signedAt,
        );
    }

    private function parseAmount(string $value): ?float
    {
        $normalized = str_replace([' ', "\u{A0}", ','], ['', '', '.'], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('d.m.Y', $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
