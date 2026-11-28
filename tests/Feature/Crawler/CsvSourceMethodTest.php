<?php

declare(strict_types=1);

use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Services\Platforms\Eis\Csv\EisCsvParser;
use App\Services\Procurement\Proxyma\Transport\CurlTransferState;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\ResponseProfile;
use Tests\Support\Platforms\Eis\EisListingFactsFixtures as C;

require_once dirname(__DIR__, 2).'/Support/ProcessCore/ProxymaReplay/ResponseFixtures.php';

test('поле в кавычках с переводом строки остаётся одной записью листинга', function (): void {
    $body = C::body([C::row('№0001', ['Наименование закупки' => "Ремонт кровли\r\nи фасада"]), C::row('№0002')]);
    $batch = (new EisCsvParser)->parseBatch($body);
    expect($batch['records'])->toBe(2)->and($batch['malformed'])->toBe(0)->and($batch['rows'])->toHaveCount(2)
        ->and($batch['rows'][0]['Наименование закупки'])->toBe("Ремонт кровли\r\nи фасада")
        ->and($batch['rows'][1]['Реестровый номер закупки'])->toBe('№0002');
});

test('обратный слэш в конце поля не склеивает соседние поля', function (): void {
    $body = C::body([C::row('№0001', ['Наименование закупки' => 'Каталог C:\\'])]);
    $batch = (new EisCsvParser)->parseBatch($body);
    expect($batch['rows'])->toHaveCount(1)->and($batch['rows'][0]['Наименование закупки'])->toBe('Каталог C:\\')
        ->and($batch['rows'][0]['Дата обновления'])->toBe('02.03.2026 10:15:00');
});

test('битая запись учитывается в rowsTotal, чтобы неполный блок не считался последним', function (): void {
    $batch = (new EisCsvParser)->parseBatch(C::body([C::row('№0001')])."\"№0002\";\"только два поля\"\r\n");
    expect($batch['records'])->toBe(2)->and($batch['malformed'])->toBe(1)->and($batch['rows'])->toHaveCount(1)
        ->and(DB::table('prc_procurements')->count())->toBe(0);
});

test('лимиты bytes records и field останавливают fresh без продвижения курсора', function (string $limit, int $value): void {
    $body = C::body([C::row('1'), C::row('2')]);
    if ($limit === 'response_max_bytes') {
        $state = new CurlTransferState(new ResponseProfile('raw', $value, $value, $value), responseScope(), $value);
        expect($state->writeChunk($body))->toBe(0)->and($state->limit)->toBeTrue();
        $reader = $state->reader ?? throw new LogicException('Response sink was not created.');
        $stream = (new ReflectionProperty($reader, 'stream'))->getValue($reader);
        $reader->close();
        expect(is_resource($stream))->toBeFalse();
    } elseif ($limit === 'csv_max_records') {
        expect(fn () => (new EisCsvParser)->parseBatch($body, maxRecords: $value))->toThrow(SourceResponseLimitExceeded::class);
    } else {
        expect(fn () => (new EisCsvParser)->parseBatch($body, maxFieldBytes: $value))->toThrow(SourceResponseLimitExceeded::class);
    }
    expect(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
})->with([['response_max_bytes', 64], ['csv_max_records', 1], ['csv_max_field_bytes', 8]]);

test('typed EIS page digest uses all observations rather than only the first registry number', function (): void {
    $a = C::parse(C::body([C::row('1'), C::row('2')]));
    $b = C::parse(C::body([C::row('1'), C::row('3')]));
    $c = C::parse(C::body([C::row('1'), C::row('2', ['Наименование закупки' => 'changed'])]));
    expect($a->summaries->digest(false)->hex)->not->toBe($b->summaries->digest(false)->hex)
        ->and($a->summaries->digest(false)->hex)->toBe($c->summaries->digest(false)->hex)
        ->and($a->summaries->digest()->hex)->not->toBe($c->summaries->digest()->hex);
});

test('typed EIS parser deadline fails before returning any partial page as successful', function (): void {
    expect(fn () => (new EisCsvParser)->parseBatch(C::body([C::row('1')]), microtime(true) - 1))
        ->toThrow(OutboundRequestDeadlineExceeded::class)
        ->and(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_request_attempts')->count())->toBe(0);
});
