<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Eis;

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Enums\Procurement\Platform\AssessmentReason as Reason;
use App\Enums\Procurement\Platform\Completeness as State;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Platforms\Eis\EisDetailSource;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use PHPUnit\Framework\Assert;
use Tests\Support\Platforms\Contract\ContractCase;
use Tests\Support\Platforms\Contract\ContractManifest;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\FixtureCorpus;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Contract\SemanticObservation as Observation;
use Tests\Support\ProcessCore\ContractFixtures;

final readonly class EisContractManifest
{
    public const string CSV = 'tests/Fixtures/Platforms/Eis/Csv/';

    public const string CARD = 'tests/Fixtures/Platforms/Eis/Card/';

    public static function request(): ListingRequest
    {
        $filter = new EisListingFilter(['123']);

        return new ListingRequest('fz44', $filter, (new EisListingCursor('2026-10-31', 'publish', 0, 200000000, $filter->fingerprint()))->envelope());
    }

    public static function listing(?RecordingOutbound $outbound = null): EisListingSource
    {
        return new EisListingSource(new EisPolicy(config()), $outbound ?? new RecordingOutbound, ContractFixtures::id());
    }

    public static function detail(?RecordingOutbound $outbound = null): EisDetailSource
    {
        $routes = new EisCardRoutes(['ea20' => [
            'common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={regNumber}',
            'documents' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html?regNumber={regNumber}',
            'event-journal' => null, 'supplier-results' => null,
        ]]);

        return new EisDetailSource(new EisPolicy(config()), $routes, app(EisDetailParser::class), $outbound ?? new RecordingOutbound, ContractFixtures::id());
    }

    public static function detailRequest(): DetailRequest
    {
        $definition = (new EisDefinition(config()))->definition();

        return new DetailRequest('fz44', new SourceIdentity($definition->code, $definition->registries->get('fz44')->primaryIdentityType,
            new SourceIdentifierValue('00042')), new CursorEnvelope('eis.card', 1, 'ea20'));
    }

    public static function manifest(): ContractManifest
    {
        $source = self::listing();
        $request = self::request();
        $valid = new Observation(State::Complete, Reason::Valid, 1, TerminalProof::ShortPage,
            identities: ['eis/registry:44fz/00042'], titles: ['Работы; дорожные'], stages: ['Подача заявок'], prices: ['1250000.50']);
        $cases = [];
        foreach ([
            'valid' => ['neutral-valid.csv', $valid],
            'empty' => ['neutral-empty.csv', new Observation(State::CompleteEmpty, Reason::Empty, terminal: TerminalProof::SemanticEmpty)],
            'truncated' => ['contract-truncated.csv', new Observation(State::Partial, Reason::SchemaMismatch, 1)],
            'overflow' => ['contract-overflow.csv', new Observation(State::Partial, Reason::Overflow, 501)],
            'blank' => ['contract-blank.csv', new Observation(State::Partial, Reason::InvalidResponse)],
        ] as $name => [$file, $expected]) {
            $path = self::CSV.$file;
            $cases[] = new ContractCase('listing', $name, [$path], $expected,
                fn (): Observation => Observation::listing($source->parse($request, ContractRuntime::response(FixtureCorpus::read($path), 'text/csv'))));
        }
        $cases[] = new ContractCase('listing', 'loop', [self::CSV.'neutral-valid.csv'],
            new Observation(State::Partial, Reason::RepeatedPage, 1, identities: $valid->identities, titles: $valid->titles, stages: $valid->stages, prices: $valid->prices),
            function () use ($source, $request): Observation {
                $response = ContractRuntime::response(FixtureCorpus::read(self::CSV.'neutral-valid.csv'), 'text/csv');
                $first = $source->parse($request, $response);

                return Observation::listing($source->parse(new ListingRequest($request->registry, $request->filter, $request->cursor, [$first->summaries->digest(false)]), $response));
            });
        $cases[] = new ContractCase('listing', 'identity_determinism', [self::CSV.'neutral-valid.csv'], $valid,
            function () use ($source, $request): Observation {
                $page = $source->parse($request, ContractRuntime::response(FixtureCorpus::read(self::CSV.'neutral-valid.csv'), 'text/csv'));
                $one = $page->summaries->items[0];
                $other = $source->parse($request, ContractRuntime::response(str_replace('00042', '42', FixtureCorpus::read(self::CSV.'neutral-valid.csv')), 'text/csv'))->summaries->items[0];
                $a = new ProcurementSummaries([$one, $other]);
                $b = new ProcurementSummaries([$other, $one]);

                return Observation::listing($page, $a->digest(false)->hex === $b->digest(false)->hex
                    && $one->identity->value->value !== $other->identity->value->value
                    && $a->digest(false)->hex !== (new ProcurementSummaries([$one]))->digest(false)->hex);
            });
        $parts = ['parties' => 'complete_empty', 'attributes' => 'complete', 'documents' => 'complete_empty', 'events' => 'not_applicable', 'results' => 'not_applicable'];
        $detail = new Observation(State::Complete, Reason::Valid, identities: $valid->identities, titles: ['Работы'], stages: $valid->stages, parts: $parts);
        foreach (['valid', 'required_sections', 'wrong_identity', 'status', 'redaction', 'login'] as $name) {
            $expected = match ($name) {
                'wrong_identity' => new Observation(State::Unavailable, Reason::IdentityMismatch),
                'required_sections' => new Observation(State::Partial, Reason::MissingSection, identities: $valid->identities, titles: ['Работы'], stages: $valid->stages, parts: array_replace($parts, ['documents' => 'unavailable'])),
                'login' => new Observation(State::Partial, Reason::MissingSection, identities: $valid->identities, titles: [''], stages: [''], parts: ['attributes' => 'partial', 'documents' => 'unavailable', 'events' => 'not_applicable', 'results' => 'not_applicable']),
                default => $detail,
            };
            $cases[] = new ContractCase('detail', $name, [self::CARD.'contract-common.html', self::CARD.'contract-documents.html', self::CARD.'contract-login.html'], $expected,
                function () use ($name): Observation {
                    $body = FixtureCorpus::read(self::CARD.($name === 'login' ? 'contract-login.html' : 'contract-common.html'));
                    if ($name === 'wrong_identity') {
                        $body = str_replace('№ 00042', '№ 000420', $body);
                    }
                    $responses = ['common-info' => ContractRuntime::response($body, 'text/html')];
                    if (! in_array($name, ['required_sections', 'login'], true)) {
                        $responses['documents'] = ContractRuntime::response(FixtureCorpus::read(self::CARD.'contract-documents.html'), 'text/html');
                    }
                    $result = self::detail()->parse(self::detailRequest(), $responses);
                    if ($name === 'redaction') {
                        Assert::assertStringNotContainsString('fixture-private-body', json_encode($result, JSON_THROW_ON_ERROR));
                    }

                    return Observation::detail($result);
                });
        }
        foreach (['split', 'bounds'] as $name) {
            $cases[] = new ContractCase('partitioning', $name, [self::CSV.'neutral-valid.csv'],
                new Observation($name === 'split' ? State::Complete : State::Partial, $name === 'split' ? Reason::Valid : Reason::Overflow, $name === 'split' ? 2 : 0),
                function () use ($request, $name): Observation {
                    $result = (new EisListingPartitioner)->split($request->cursor, $name === 'split' ? 1 : 9999);
                    if ($name === 'split') {
                        $left = EisListingCursor::decode($result->children[0]);
                        $right = EisListingCursor::decode($result->children[1]);
                        Assert::assertSame([0, 100000000, 100000000, 200000000], [$left->lowCents, $left->highCents, $right->lowCents, $right->highCents]);
                    }

                    return new Observation($result->incompleteReason === null ? State::Complete : State::Partial,
                        $result->incompleteReason ?? Reason::Valid, count($result->children));
                });
        }

        return new ContractManifest((new EisDefinition(config()))->definition(), $cases, ['listing.blank', 'detail.login']);
    }
}
