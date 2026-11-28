<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\BidData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\ProtocolData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\ProtocolType;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\DriftCollector;
use Illuminate\Support\Carbon;
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
    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(
        private EisSourceClock $clock = new EisSourceClock,
        private ?DetailRuleSetDto $rules = null,
        ?DomExtractor $dom = null,
        private ?DriftCollector $drift = null,
    ) {}

    private const string TERM_PROTOCOL = 'Наименование протокола определения поставщика (подрядчика, исполнителя)';

    private const string TERM_FORMED_AT = 'Дата и время формирования результатов определения поставщика (подрядчика, исполнителя)';

    /**
     * @var array<string, ProtocolType>
     */
    private const array PROTOCOL_TYPE_MAP = EisDefaultParsingRules::PROTOCOL_TYPE_MAP;

    public function parse(string $html, bool $strict = false): SupplierResultsData
    {
        try {
            $rules = $this->rules ?? EisDefaultParsingRules::detail();
            $crawler = new Crawler($html);
            $protocol = $this->parseProtocol(CardDomHelper::readDefinitionValue($crawler, self::TERM_PROTOCOL, $rules->dom));
            $bids = $this->parseBids($crawler, $rules);
            $formedAt = $this->parseDateTime(CardDomHelper::readDefinitionValue($crawler, self::TERM_FORMED_AT, $rules->dom));
            $award = $this->buildAward($bids, $protocol, $formedAt);

            if ($protocol === null && $bids === []) {
                $this->drift?->record(new DriftSignal('supplier_results_empty', StageCode::Detail, 'supplier-def-result-participant-table'));
            }

            $this->warnIfBlockInfoSuppressed($crawler, $protocol, $bids);

            return new SupplierResultsData(protocol: $protocol, bids: $bids, award: $award);
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            CardDomHelper::logWarning('Разбор вкладки supplier-results провалился целиком, вкладка пропущена', [
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
            CardDomHelper::logWarning('Строка протокола на вкладке supplier-results не распознана', ['text' => $normalized]);

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
    private function parseBids(Crawler $crawler, DetailRuleSetDto $rules): array
    {
        $bids = [];
        $tables = $this->firstMatching($crawler, $rules->supplierResults?->containerSelectors, 'div[id^="supplier-def-result-participant-table-"] table.tableBlock');
        $tables->each(function (Crawler $table) use (&$bids, $rules): void {
            $columns = $this->bidColumns($table);
            $rows = $this->firstMatching($table, $rules->supplierResults?->participantRowSelectors, 'tbody > tr');
            $rows->each(function (Crawler $row) use ($columns, &$bids): void {
                $bid = $this->parseBidRow(row: $row, columns: $columns);
                if ($bid !== null) {
                    $bids[] = $bid;
                }
            });
        });

        return $bids;
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

    /** @return array<string, int> */
    private function bidColumns(Crawler $table): array
    {
        $columns = ['number' => 0, 'rank' => 1, 'price' => 2];
        $table->children('thead')->filter('th')->each(static function (Crawler $header, int $index) use (&$columns): void {
            $label = mb_strtolower(CardDomHelper::normalizeWhitespace($header->text()));
            $key = match (true) {
                str_contains($label, 'идентификационный номер'), str_contains($label, 'идентификационные номера'),
                str_contains($label, 'номер заявки') => 'number',
                str_contains($label, 'порядков'), str_contains($label, 'место') => 'rank',
                str_contains($label, 'статус'), str_contains($label, 'результат рассмотрения') => 'status',
                str_contains($label, 'предложени'), str_contains($label, 'цена') => 'price',
                preg_match('/\bинн\b/u', $label) === 1 => 'inn',
                preg_match('/\bкпп\b/u', $label) === 1 => 'kpp',
                preg_match('/\bогрн(?:ип)?\b/u', $label) === 1 => 'ogrn',
                str_contains($label, 'наименование'), str_contains($label, 'участник') => 'name',
                default => null,
            };
            if ($key !== null) {
                $columns[$key] = $index;
            }
        });

        return $columns;
    }

    /** @param array<string, int> $columns */
    private function parseBidRow(Crawler $row, array $columns): ?BidData
    {
        $cells = $row->children('td');

        if ($cells->count() < 3) {
            return null;
        }

        $cell = static fn (string $key): string => isset($columns[$key])
            ? CardDomHelper::normalizeWhitespace($cells->eq($columns[$key])->text('')) : '';
        $bidNumber = $cell('number');
        $rank = $cell('rank');
        $price = CardDomHelper::normalizeMoney($cell('price'));
        $description = mb_strtolower($rank.' '.$cell('status'));
        $status = match (true) {
            str_contains($description, 'отозва'), str_contains($description, 'отзыв') => BidStatus::Withdrawn,
            str_contains($description, 'отклон'), str_contains($description, 'не допущ') => BidStatus::Rejected,
            str_contains($description, 'победител') => BidStatus::Winner,
            default => BidStatus::Admitted,
        };
        $participantOrdinal = $this->parseLeadingInteger($rank);
        $name = $cell('name');
        $inn = CardDomHelper::extractInn($cell('inn'));
        $ogrn = preg_match('/(?<!\d)(\d{15}|\d{13})(?!\d)/u', $cell('ogrn'), $match) === 1 ? $match[1] : null;
        $kpp = preg_match('/(?<!\d)(\d{9})(?!\d)/u', $cell('kpp'), $match) === 1 ? $match[1] : null;
        $organization = $name !== '' && ($inn !== null || $ogrn !== null)
            ? new OrganizationData(fullName: $name, inn: $inn, kpp: $kpp, ogrn: $ogrn) : null;

        if ($bidNumber === '' && trim($description) === '' && $price === null && $organization === null) {
            return null;
        }

        return new BidData(
            organization: $organization,
            participantOrdinal: $participantOrdinal,
            price: $price,
            status: $status,
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
        $winners = array_values(array_filter($bids,
            static fn (BidData $bid): bool => $bid->status === BidStatus::Winner,
        ));

        if (count($winners) !== 1) {
            return null;
        }

        $winningBid = $winners[0];

        return new AwardData(
            winner: $winningBid->organization,
            finalPrice: $winningBid->price,
            status: AwardStatus::Awarded,
            awardedAt: $protocol === null
                ? $formedAt
                : ($protocol->publishedAt ?? $protocol->signedAt ?? $formedAt),
        );
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

        CardDomHelper::warnIfBlockInfoSuppressed($crawler, 'На вкладке supplier-results есть блоки blockInfo, но не распознано ни протокола, ни заявок');
    }
}
