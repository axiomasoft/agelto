<?php

declare(strict_types=1);

namespace Tests\Support\Crawler;

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Services\Procurement\Exceptions\CardPageMismatchException;
use LogicException;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\Doubles\Crawler\ScriptedSourceMethod;

/**
 * Строительные блоки сценариев надёжности: шаги источника (ответы и отказы) и установка
 * `ScriptedSourceMethod` вместо адаптера ЕИС. Часы — штатные `travel()`/`Carbon::setTestNow`.
 */
final class SourceControlScenario
{
    public const string CardNumber = '0338200009125000001';

    public static function install(ScriptedSourceMethod $source): ScriptedSourceMethod
    {
        app()->instance(ScriptedSourceMethod::class, $source);
        config([
            'crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => ScriptedSourceMethod::class],
            'crawler.detail_source_method' => SourceMethodEnum::Csv->value,
        ]);

        return $source;
    }

    /** @return array<string, string> строка CSV, которую понимает `EisCsvRowMapper` */
    public static function row(string $registryNumber): array
    {
        return [
            'Реестровый номер закупки' => $registryNumber,
            'Способ определения поставщика' => 'Электронный аукцион',
            'Наименование закупки' => 'Поставка оргтехники',
            'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
            'НМЦК' => '1 250 000,50',
            'Валюта' => 'RUB',
            'ОКПД2' => '26.20.11.110',
            'Дата размещения' => '01.03.2026',
            'Дата обновления' => '02.03.2026 10:15:00',
            'Начало подачи заявок' => '01.03.2026',
            'Окончание подачи заявок' => '15.03.2026',
            'Наименование заказчика' => 'ГБУ «Тестовое учреждение»',
        ];
    }

    /** Блок с закупками (номера уникальны по `$seed`). */
    public static function rows(int $seed, int $count = 2): ListingBatch
    {
        $rows = [];

        foreach (range(1, $count) as $i) {
            $rows[] = self::row(sprintf('01732000012340%05d', $seed * 100 + $i));
        }

        return self::batch($rows, 200, 'csv '.$seed, signature: $rows[0]['Реестровый номер закупки']);
    }

    /** Пустая выдача с заголовком — нормальный конец данных. */
    public static function end(): ListingBatch
    {
        return self::batch([], 200, 'header-only');
    }

    public static function status(int $status): ListingBatch
    {
        return self::batch([], $status, "<html>{$status}</html>");
    }

    public static function emptyBody(): ListingBatch
    {
        return new ListingBatch(rows: [], rawBody: '', dedupSignature: null, rowsTotal: 0, httpStatus: 200, durationMs: 1, requestParams: [], emptyBody: true);
    }

    /** Формат сменился: нет обязательной колонки «Дата обновления». */
    public static function drift(int $seed): ListingBatch
    {
        $row = self::row(sprintf('01732000099990%05d', $seed));
        unset($row['Дата обновления']);

        return self::batch([$row], 200, 'drift '.$seed, header: array_keys($row));
    }

    public static function noProxy(): OutboundUnavailable
    {
        return new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
    }

    public static function network(): OutboundUnavailable
    {
        return new OutboundUnavailable(new TransportDeferred(NetworkDeferralReason::TargetCooldown, new RetryAt(new \DateTimeImmutable('+1 minute'))));
    }

    public static function bug(): LogicException
    {
        return new LogicException('bug in our code');
    }

    public static function card(): ProcurementDetails
    {
        return new ProcurementDetails(
            registryNumber: self::CardNumber,
            procedureUrlType: 'ea20',
            commonInfoHtml: CardHtmlFixture::commonInfo(),
            documentsHtml: CardHtmlFixture::documents(),
            eventJournalHtml: CardHtmlFixture::eventJournal(),
            supplierResultsHtml: CardHtmlFixture::supplierResults(),
        );
    }

    /** Карточка с номером, но без базовых селекторов (новая вёрстка). */
    public static function cardDrift(): ProcurementDetails
    {
        $stub = '<html><body><p>'.self::CardNumber.' новая вёрстка</p></body></html>';

        return new ProcurementDetails(self::CardNumber, 'ea20', $stub, $stub, $stub, $stub);
    }

    public static function cardStatus(int $status): CardPageMismatchException
    {
        return CardPageMismatchException::forTab('common-info', 'https://zakupki.gov.ru/x', $status, 10);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>|null  $header
     */
    private static function batch(array $rows, int $status, string $body, ?string $signature = null, ?array $header = null): ListingBatch
    {
        return new ListingBatch(
            rows: $rows,
            rawBody: $body,
            dedupSignature: $signature,
            rowsTotal: count($rows),
            httpStatus: $status,
            durationMs: 1,
            requestParams: [],
            header: $header ?? ($rows === [] ? null : array_keys($rows[0])),
        );
    }
}
