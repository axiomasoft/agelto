<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\BidData;
use App\Dto\Procurement\Details\ProtocolData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\ProtocolType;
use App\Services\Platforms\Eis\EisSourceClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `supplier-results` карточки ЕИС в {@see SupplierResultsData}. Реальная
 * success-фикстура снята 2026-08-07 с карточки 0356500001426005498: старые селекторы
 * `div.supplier-results`/`dl`/`table.bids-table` мертвы, текущая раскладка использует
 * `section.blockInfo__section` и `table.tableBlock`. У `ea615`/`po615` вкладки нет по природе.
 *
 * ЕИС публикует идентификатор заявки, ранг победителя и цену раньше реквизитов организации,
 * поэтому подтверждённый award может иметь nullable winner. Только HTML → DTO, без
 * записи в БД; пустой/неопознанный HTML даёт пустой результат, не исключение.
 */
final readonly class SupplierResultsParser
{
    public function __construct(private EisSourceClock $clock = new EisSourceClock) {}

    private const string TERM_PROTOCOL = 'Наименование протокола определения поставщика (подрядчика, исполнителя)';

    private const string TERM_FORMED_AT = 'Дата и время формирования результатов определения поставщика (подрядчика, исполнителя)';

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

    public function parse(string $html, bool $strict = false): SupplierResultsData
    {
        try {
            $crawler = new Crawler($html);
            $protocol = $this->parseProtocol($this->fieldValue($crawler, self::TERM_PROTOCOL));
            $bids = $this->parseBids($crawler);
            $formedAt = $this->parseDateTime($this->fieldValue($crawler, self::TERM_FORMED_AT));
            $award = $this->buildAward($bids, $protocol, $formedAt);

            $this->warnIfBlockInfoSuppressed($crawler, $protocol, $bids);

            return new SupplierResultsData(protocol: $protocol, bids: $bids, award: $award);
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            Log::warning('Разбор вкладки supplier-results провалился целиком, вкладка пропущена', [
                'exception' => $exception->getMessage(),
            ]);

            return new SupplierResultsData(protocol: null, bids: [], award: null);
        }
    }

    private function parseProtocol(?string $text): ?ProtocolData
    {
        if ($text === null || $text === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $matched = preg_match(
            '/^Протокол\s+(.+?)\s+от\s+(\d{2}\.\d{2}\.\d{4})\s+№\s*(\S+)/ui',
            $normalized,
            $matches,
        );

        if ($matched !== 1) {
            Log::warning('Строка протокола на вкладке supplier-results не распознана', ['text' => $normalized]);

            return null;
        }

        [, $typeText, $dateText, $sourceNumber] = $matches;
        $date = $this->parseDate($dateText);

        return new ProtocolData(
            type: $this->protocolType($typeText),
            sourceNumber: $sourceNumber,
            signedAt: $date,
            publishedAt: $date,
        );
    }

    private function protocolType(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        foreach (self::PROTOCOL_TYPE_MAP as $fragment => $type) {
            if (str_contains($normalized, $fragment)) {
                return $type->value;
            }
        }

        return trim($text);
    }

    /**
     * @return list<BidData>
     */
    private function parseBids(Crawler $crawler): array
    {
        $table = $crawler
            ->filter('div[id^="supplier-def-result-participant-table-"] table.tableBlock')
            ->first();

        if ($table->count() === 0) {
            return [];
        }

        $bids = [];

        $table->filter('tbody tr')->each(function (Crawler $row) use (&$bids): void {
            $bid = $this->parseBidRow($row);

            if ($bid !== null) {
                $bids[] = $bid;
            }
        });

        return $bids;
    }

    private function parseBidRow(Crawler $row): ?BidData
    {
        $cells = $row->filter('td');

        if ($cells->count() < 3) {
            return null;
        }

        $bidNumber = trim($cells->eq(0)->text());
        $rank = trim($cells->eq(1)->text());
        $price = $this->parseAmount(trim($cells->eq(2)->text()));
        $isWinner = str_contains(mb_strtolower($rank), 'победител');
        $participantOrdinal = $this->parseLeadingInteger($rank);

        if ($bidNumber === '' && $rank === '' && $price === null) {
            return null;
        }

        return new BidData(
            organization: null,
            participantOrdinal: $participantOrdinal,
            price: $price,
            status: $isWinner ? BidStatus::Winner : BidStatus::Admitted,
            bidNumber: $bidNumber !== '' ? $bidNumber : null,
        );
    }

    private function parseLeadingInteger(string $value): ?int
    {
        if (preg_match('/^\s*(\d+)/u', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param  list<BidData>  $bids
     */
    private function buildAward(array $bids, ?ProtocolData $protocol, ?Carbon $formedAt): ?AwardData
    {
        $winningBid = collect($bids)->first(
            static fn (BidData $bid): bool => $bid->status === BidStatus::Winner,
        );

        if (! $winningBid instanceof BidData) {
            return null;
        }

        return new AwardData(
            winner: $winningBid->organization,
            finalPrice: $winningBid->price,
            status: AwardStatus::Awarded,
            awardedAt: $protocol === null
                ? $formedAt
                : ($protocol->publishedAt ?? $protocol->signedAt ?? $formedAt),
        );
    }

    private function fieldValue(Crawler $crawler, string $term): ?string
    {
        $section = $this->titleSection($crawler, $term);
        $info = $section !== null ? $this->infoNode($section) : null;

        if ($info === null) {
            return null;
        }

        $value = trim($info->text());

        return $value !== '' ? $value : null;
    }

    private function titleSection(Crawler $crawler, string $term): ?Crawler
    {
        try {
            $title = $crawler
                ->filter('span.section__title')
                ->reduce(static fn (Crawler $span): bool => trim($span->text()) === $term);

            if ($title->count() === 0) {
                return null;
            }

            $section = $title->ancestors()->first();

            return $section->count() > 0 ? $section : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function infoNode(Crawler $section): ?Crawler
    {
        $info = $section->filter('span.section__info')->first();

        return $info->count() > 0 ? $info : null;
    }

    private function parseAmount(string $value): ?float
    {
        $normalized = str_replace([' ', "\u{A0}", ','], ['', '', '.'], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseDate(string $value): ?Carbon
    {
        return $this->clock->parseDate(format: 'd.m.Y', value: $value);
    }

    private function parseDateTime(?string $value): ?Carbon
    {
        if ($value === null || preg_match('/(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2})/u', $value, $matches) !== 1) {
            return null;
        }

        return $this->clock->parseDateTime(format: '!d.m.Y H:i', value: $matches[1]);
    }

    /**
     * @param  list<BidData>  $bids
     */
    private function warnIfBlockInfoSuppressed(Crawler $crawler, ?ProtocolData $protocol, array $bids): void
    {
        if ($protocol !== null || $bids !== []) {
            return;
        }

        $sections = $crawler->filter('section.blockInfo__section')->count();

        if ($sections > 0) {
            Log::warning('На вкладке supplier-results есть блоки blockInfo, но не распознано ни протокола, ни заявок', [
                'sections' => $sections,
            ]);
        }
    }
}
