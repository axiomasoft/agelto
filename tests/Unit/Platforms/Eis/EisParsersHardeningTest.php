<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DbInstant;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Services\Platforms\Eis\Card\CardDomHelper;
use App\Services\Platforms\Eis\Card\CommonInfoParser;
use App\Services\Platforms\Eis\Card\DocumentsParser;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\Card\EventJournalParser;
use App\Services\Platforms\Eis\Card\PartiesParser;
use App\Services\Platforms\Eis\Card\SupplierResultsParser;
use App\Services\Platforms\Eis\Csv\EisCsvParser;
use App\Services\Platforms\Eis\EisDetailSource;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Network\ResponseDecoder;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Illuminate\Support\Facades\Log;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\ProcessCore\ContractFixtures;

test('отсутствующая НМЦК проходит ценовой фильтр листинга и сохраняется как null', function (): void {
    $base = EisContractManifest::request();
    $cursor = new EisListingCursor(day: '2026-10-31', dateField: 'publish', lowCents: 100, highCents: 200, filterHash: $base->filter->fingerprint());
    $request = new ListingRequest(registry: 'fz44', filter: $base->filter, cursor: $cursor->envelope());
    $header = 'Реестровый номер закупки;Наименование закупки;Этап закупки;Дата размещения;Дата обновления;Начальная (максимальная) цена контракта';
    $row = '00042;Работы;Подача заявок;31.10.2026;31.10.2026;';
    $source = EisContractManifest::listing();

    $page = $source->parse(request: $request, response: ContractRuntime::response("{$header}\n{$row}\n", 'text/csv'));
    expect($page->assessment->reason)->toBe(AssessmentReason::Valid)
        ->and($page->summaries->items[0]->initialPrice)->toBeNull();
    expect($source->parse(request: $request, response: ContractRuntime::response("{$header}\n{$row}0\n", 'text/csv'))->assessment->reason)
        ->toBe(AssessmentReason::FilterMismatch);
    expect($source->parse(request: $request, response: ContractRuntime::response("{$header}\n{$row}ошибка\n", 'text/csv'))->assessment->reason)
        ->toBe(AssessmentReason::SchemaMismatch);
});

test('результаты читают все таблицы с точными ценами, статусами и идентификаторами победителя', function (): void {
    $html = <<<'HTML'
        <div id="supplier-def-result-participant-table-1"><table class="tableBlock"><tbody>
          <tr><td>11</td><td>1 — заявка отклонена</td><td>12,30</td></tr>
          <tr><td>12</td><td>2 — заявка отозвана</td><td></td></tr>
        </tbody></table></div>
        <div id="supplier-def-result-participant-table-2"><table class="tableBlock">
          <thead><tr><th>Номер заявки</th><th>Наименование участника</th><th>ИНН</th><th>КПП</th><th>Порядковый номер</th><th>Цена</th><th>Статус</th></tr></thead>
          <tbody><tr><td>21</td><td>ООО «Победитель»</td><td>7701234567</td><td>770101001</td><td>1</td><td>9 007 199 254 740 993,01</td><td>Победитель</td></tr></tbody>
        </table></div>
        HTML;
    $parser = new SupplierResultsParser;
    $results = $parser->parse(html: $html, strict: true);

    expect(array_column($results->bids, 'bidNumber'))->toBe(['11', '12', '21'])
        ->and(array_column($results->bids, 'status'))->toBe([BidStatus::Rejected, BidStatus::Withdrawn, BidStatus::Winner])
        ->and(array_column($results->bids, 'price'))->toBe(['12.30', null, '9007199254740993.01'])
        ->and($results->award?->finalPrice)->toBe('9007199254740993.01')
        ->and($results->award?->winner?->inn)->toBe('7701234567');

    $twoWinners = $parser->parse(html: str_replace('1 — заявка отклонена', '1 — победитель', $html), strict: true);
    expect($twoWinners->bids)->toHaveCount(3)->and($twoWinners->award)->toBeNull();
});

test('старые числовые цены результатов читаются как десятичные строки', function (): void {
    $codec = new DetailValueCodec;
    $award = new AwardData(winner: null, finalPrice: '120.05', status: AwardStatus::Awarded);
    $legacy = str_replace('"120.05"', '120.05', $codec->encode($award));

    expect($codec->decode($legacy))->toEqual($award);
});

test('усечённые таблицы КТРУ сохраняют разобранные позиции с уникальными номерами', function (): void {
    $html = <<<'HTML'
        <section class="blockInfo__section"><span class="section__title">Финансовое обеспечение закупки (всего)</span>
          <table class="tableBlock"><tr class="tableBlock__row"><th>2026 год</th></tr><tr><td>100,00</td></tr></table>
        </section>
        <div id="positionKTRU">
          <table class="tableBlock"><thead><tr><th>№</th><th>Код</th><th>Название</th><th>Количество</th><th>Сумма</th></tr></thead>
            <tbody><tr><td>1</td><td>41.20</td><td>Первый</td><td>2</td><td>100,00</td></tr></tbody>
            <tfoot><tr><td><span class="tableBlock__resultTitle">Итого (3 записи):</span></td></tr></tfoot></table>
          <table class="tableBlock"><thead><tr><th>№</th><th>Код</th><th>Название</th><th>Единица</th><th>Цена</th><th>Сумма</th></tr></thead>
            <tbody><tr><td>1</td><td>41.20.10-00000001</td><td>Второй</td><td>шт</td><td>30,55</td><td>0,00</td></tr></tbody>
            <tfoot><tr><td><span class="tableBlock__resultTitle">Итого (1 запись):</span></td></tr></tfoot></table>
          <div class="select-record-per-page--number" data-selected-value="1">1</div>
        </div>
        HTML;
    $snapshot = (new CommonInfoParser)->parse(html: $html, strict: true);
    expect($snapshot->status)->toBe(DetailSnapshotStatus::Incomplete)
        ->and(array_column($snapshot->commonInfo->items, 'positionNumber'))->toBe([1, 2])
        ->and($snapshot->commonInfo->items[0]->quantity)->toBe('2')
        ->and($snapshot->commonInfo->items[1]->unitPrice)->toBe('30.55');
    expect((new CommonInfoParser)->parse(str_replace('3 записи', '1 запись', $html))->status)->toBe(DetailSnapshotStatus::Complete);
});

test('совместные торги читают альтернативную цену и допускают её отсутствие', function (string $label): void {
    $body = file_get_contents(base_path(EisContractManifest::CARD.'common-info.html'));
    assert(is_string($body));
    $price = '<section class="blockInfo__section"><span class="section__title">'.$label.'</span><span class="section__info">1 234,56 ₽</span></section>';
    $html = str_replace('</body>', $price.'</body>', $body);
    $parser = new CommonInfoParser;

    expect($parser->parse($html)->commonInfo->initialPrice)->toBe('1234.56')
        ->and($parser->parse($body)->status)->toBe(DetailSnapshotStatus::Complete);
})->with(['Максимальное значение цены контракта', 'Начальная сумма цен единиц товара, работы, услуги']);

test('неполные роли и неизвестные кавычки оставляют проверенного разместителя в частичном результате', function (string $needle, string $replacement): void {
    Log::spy();
    $body = file_get_contents(base_path(EisContractManifest::CARD.'parties-january-1.html'));
    assert(is_string($body));
    $html = str_replace($needle, $replacement, $body);
    $parser = new PartiesParser;
    $parties = $parser->parse(html: $html, strict: true);

    expect($parties[0]->role)->toBe(ProcurementPartyRole::Placer)
        ->and($parser->authorizesReconciliation(html: $html, parties: $parties, strict: true))->toBeFalse();
    Log::shouldHaveReceived('warning')->atLeast()->once();
})->with([
    'неизвестная роль' => ['Уполномоченный орган', 'Новая роль'],
    'неизвестная кавычка' => ['Требования заказчика «', 'Требования заказчика ‹'],
]);

test('разместитель добавляется один раз и альтернативная ссылка сохраняет код организации', function (): void {
    $body = file_get_contents(base_path(EisContractManifest::CARD.'parties-january-1.html'));
    assert(is_string($body));
    $html = str_replace('Уполномоченный орган', 'Размещающая организация', $body);
    $html = str_replace('<a target="_blank" href="/epz/organization/view/info.html?', '<p class="blockInfo__section"><a target="_blank" href="/epz/organization/view/info.html?', $html);
    $html = preg_replace('#(organizationCode=01013000002.*?</a>)#s', '$1</p>', $html) ?? $html;
    $parser = new PartiesParser;
    $parties = $parser->parse(html: $html, strict: true);

    expect(array_column($parties, 'role'))->toBe([ProcurementPartyRole::Placer, ProcurementPartyRole::Customer])
        ->and($parties[0]->organization->identifiers[0]->value)->toBe('01013000002')
        ->and($parser->authorizesReconciliation(html: $html, parties: $parties, strict: true))->toBeTrue();
    expect(CardDomHelper::extractInn('р/с 03221643400000001500'))->toBeNull()
        ->and(CardDomHelper::extractInn('р/с 03221643400000001500, ИНН 7701234567'))->toBe('7701234567');
});

test('имя заказчика без ссылки восстанавливается по данным карточки и подтверждённому ИКЗ', function (): void {
    $body = file_get_contents(base_path(EisContractManifest::CARD.'parties-january-8.html'));
    assert(is_string($body));
    $html = preg_replace('#<a[^>]+organizationCode=08696000222[^>]*>.*?</a>#s', '', $body) ?? $body;
    $parser = new PartiesParser;
    $parties = $parser->parse(html: $html, strict: true);

    expect($parser->authorizesReconciliation(html: $html, parties: $parties, strict: true))->toBeTrue()
        ->and($parties[1]->role)->toBe(ProcurementPartyRole::Customer)
        ->and($parties[1]->organization->fullName)->toContain('КИЗИЛЬСКАЯ ШКОЛА')
        ->and($parties[1]->organization->identifiers[0]->type)->toBe('customer_code');
});

test('документы считаются по уникальным uid только в контейнере вложений', function (): void {
    $link = '<span class="section__value"><a href="/44fz/filestore/file.html?uid=doc1">Файл</a></span>';
    $attachment = '<div class="attachment">'.$link.'</div>';
    $html = '<a href="/44fz/filestore/file.html?uid=outside">Ссылку меню не учитывать</a><div class="blockFilesTabDocs">'
        .$attachment.$attachment.$link.'</div>';
    $parser = app(DocumentsParser::class);
    $snapshot = $parser->parse(html: $html, strict: true);

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Complete)->and(array_column($snapshot->documents, 'externalId'))->toBe(['doc1']);
    $missing = '<div class="blockFilesTabDocs">'.$attachment.'<a href="/44fz/filestore/file.html?uid=unparsed">Скрытый</a></div>';
    expect($parser->parse($missing)->status)->toBe(DetailSnapshotStatus::Incomplete);
});

test('журнал сохраняет секунды и предупреждает о неизвестном часовом поясе', function (): void {
    Log::spy();
    $html = '<table id="event"><tbody><tr><td>30.06.2026 08:22:47 (МСК+3)</td><td>Первое</td></tr>'
        .'<tr><td>30.06.2026 09:10:15 (МСК+99)</td><td>Второе</td></tr></tbody></table>';
    $rows = (new EventJournalParser)->parse(html: $html, strict: true);

    expect($rows[0]->occurredAt->toIso8601String())->toBe('2026-06-30T02:22:47+00:00')
        ->and($rows[1]->occurredAt->toIso8601String())->toBe('2026-06-30T06:10:15+00:00');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'часового пояса')
        && $context['timezone'] === 'МСК+99')->once();
});

test('неизвестный способ закупки отклоняется до HTTP с доказательством из входных данных', function (): void {
    $outbound = new RecordingOutbound;
    $source = EisContractManifest::detail($outbound);
    $base = EisContractManifest::detailRequest();
    $request = new DetailRequest(registry: $base->registry, identity: $base->identity, locator: new CursorEnvelope('eis.procedure', 1, 'Неизвестный способ'));
    $outcome = $source->fetchPart(request: $request, part: 'common-info', context: ContractRuntime::context());
    if (! $outcome instanceof FetchItemRejected) {
        throw new LogicException('Ожидалось отклонение входа');
    }

    expect($outcome->reason)->toBe(ItemFailureReason::InvalidInput)
        ->and($outcome->assessment->failureScope)->toBe(FailureScope::Item)
        ->and($outcome->assessment->bodyHash->hex)->toBe(ContentHash::of($outcome->inputEvidence ?? '')->hex)
        ->and($outbound->plans)->toBeEmpty();
});

test('повтор после WAF отсчитывается от времени базы данных', function (bool $standalone): void {
    $databaseTime = new DateTimeImmutable('2026-09-01T10:00:00Z');
    if ($standalone) {
        $clock = Mockery::mock(ProcessClock::class);
        $clock->expects('dbNow')->andReturn(new DbInstant($databaseTime));
        $deadline = $databaseTime->modify('+2 minutes');
        $handle = new OutboundOperationHandle(id: ContractFixtures::id(), kind: OutboundOperationKind::Diagnostic,
            generation: 1, ownerToken: ContractFixtures::id(2), leaseUntil: $deadline, deadlineAt: $deadline);
        $context = new OutboundExecutionContext(handle: $handle, renew: static fn () => $handle,
            checkpoint: null, deadlineSeconds: 120, renewIntervalSeconds: 15, clock: $clock);
    } else {
        $context = ContractRuntime::context(databaseTime: $databaseTime);
    }
    $outbound = new RecordingOutbound([new TransportSucceeded(new ResponseEnvelope(429, [], new DecodedTextBody('WAF', 'UTF-8'), 3, 3))]);
    $routes = new EisCardRoutes(['ea20' => [
        'common-info' => 'https://zakupki.gov.ru/common-info?regNumber={regNumber}',
        'documents' => 'https://zakupki.gov.ru/documents?regNumber={regNumber}',
        'event-journal' => null, 'supplier-results' => null,
    ]]);
    $source = new EisDetailSource(policy: new EisPolicy(config()), routes: $routes, parser: app(EisDetailParser::class),
        outbound: $outbound, targetId: ContractFixtures::id());
    $outcome = $source->fetchPart(request: EisContractManifest::detailRequest(), part: 'common-info', context: $context);
    if (! $outcome instanceof FetchDeferred) {
        throw new LogicException('Ожидалась отсрочка');
    }

    expect($outcome->reason)->toBe(NetworkDeferralReason::RateLimit)
        ->and($outcome->retryAt->value->format(DATE_ATOM))->toBe('2026-09-01T10:01:00+00:00');
})->with(['воркер' => false, 'отдельная операция' => true]);

test('кавычки и переносы CSV сохраняются на границе 64 КиБ', function (): void {
    $header = "Реестровый номер закупки;Дата размещения;Дата обновления;Наименование закупки\r\n";
    $prefix = '42;01.01.2026;01.01.2026;"';
    $text = str_repeat('x', 65_535 - strlen($header.$prefix));
    $body = $header.$prefix.$text."\"\"\r\nпродолжение\"\r\n";
    $rows = (new EisCsvParser)->parse($body);

    expect($rows)->toHaveCount(1)->and($rows[0]['Наименование закупки'])->toBe($text."\"\r\nпродолжение");
});

test('HTML карточки и диагностики без объявления кодировки читается как UTF-8', function (): void {
    $html = '<p>Текст закупки</p>';
    $policy = new EisPolicy(config());
    $decoder = new ResponseDecoder;
    foreach (['card', 'diagnostic'] as $endpoint) {
        $decoded = $decoder->decode(bytes: $html, contentType: 'text/html', profile: $policy->endpoints->get($endpoint)->profile);
        expect($decoded->utf8)->toBe($html)->and($decoded->sourceCharset)->toBe('UTF-8');
    }
});
