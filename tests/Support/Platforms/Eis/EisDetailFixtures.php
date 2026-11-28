<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Eis;

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\EisDetailSource;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\ProcessCore\LedgerFixtures;

final class EisDetailFixtures
{
    public static function request(string $id = '0338200009125000001'): DetailRequest
    {
        return new DetailRequest('fz44', new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType('registry:44fz'),
            new SourceIdentifierValue($id)), new CursorEnvelope('eis.card', 1, 'ea20'));
    }

    public static function bodies(): array
    {
        // Historical files are tab excerpts; supply the required common page identity shell.
        $shell = '<a class="cardMainInfo__purchaseLink">№ 0338200009125000001</a>'
            .'<div class="cardMainInfo__purchaseObject">Поставка оргтехники</div>'
            .'<div class="cardMainInfo__state">Определение поставщика завершено</div>';

        return array_map(static fn (string $body) => $shell.$body, ['common-info' => CardHtmlFixture::commonInfo(), 'documents' => CardHtmlFixture::documents(),
            'event-journal' => CardHtmlFixture::eventJournal(), 'supplier-results' => CardHtmlFixture::supplierResults()]);
    }

    public static function outbound(array $bodies): RecordingOutbound
    {
        return new RecordingOutbound(array_map(static fn (string $body) => new TransportSucceeded(ContractRuntime::response($body, 'text/html')), array_values($bodies)));
    }

    /** Synthetic multi-tab composition; the independent observed success corpus stays unchanged. */
    public static function completeBodies(): array
    {
        $bodies = self::bodies();
        $success = new Crawler(file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/supplier-results-success.html')));
        $parts = $success->filter('span.section__title')->reduce(static fn ($node) => str_starts_with(trim($node->text()), 'Наименование протокола определения поставщика')
                || str_starts_with(trim($node->text()), 'Дата и время формирования результатов'))
            ->each(static fn ($node) => $node->ancestors()->filter('section.blockInfo__section')->first()->outerHtml());
        $parts[] = $success->filter('div[id^="supplier-def-result-participant-table-"]')->first()->outerHtml();
        $bodies['supplier-results'] = '<a class="cardMainInfo__purchaseLink">№ 0338200009125000001</a>'.implode('', $parts);

        return $bodies;
    }

    public static function source(RecordingOutbound $outbound, bool $results = true): EisDetailSource
    {
        $tabs = [];
        foreach (array_keys(self::bodies()) as $tab) {
            $tabs[$tab] = $tab === 'supplier-results' && ! $results ? null
                : 'https://zakupki.gov.ru/epz/order/notice/ea20/view/'.$tab.'.html?regNumber={regNumber}';
        }

        return new EisDetailSource(new EisPolicy(config()), new EisCardRoutes(['ea20' => $tabs]), app(EisDetailParser::class), $outbound, LedgerFixtures::uuid());
    }
}
