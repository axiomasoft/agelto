<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Enums\Procurement\Platform\AssessmentReason as Reason;
use App\Enums\Procurement\Platform\Completeness as State;
use App\Enums\Procurement\Platform\TerminalProof;
use PHPUnit\Framework\Assert;
use Tests\Support\Platforms\Contract\ContractCase;
use Tests\Support\Platforms\Contract\ContractManifest;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\FixtureCorpus;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Contract\SemanticObservation as Observation;
use Tests\Support\ProcessCore\ContractFixtures;

final readonly class JsonContractManifest
{
    public const string ROOT = 'tests/Fixtures/ProcessCore/JsonPlatform/';

    public static function source(?RecordingOutbound $outbound = null): JsonProcurementSource
    {
        return new JsonProcurementSource((new JsonProcurementDefinition)->definition(), $outbound ?? new RecordingOutbound, ContractFixtures::id());
    }

    public static function request(string $cursor = 'opaque:initial'): ListingRequest
    {
        return new ListingRequest('fz44', new JsonListingFilter('roads'), new CursorEnvelope('json.listing', 1, $cursor));
    }

    public static function detailRequest(): DetailRequest
    {
        $definition = (new JsonProcurementDefinition)->definition();

        return new DetailRequest('fz44', new SourceIdentity($definition->code, $definition->registries->get('fz44')->primaryIdentityType, new SourceIdentifierValue(' 00:Ab-/42 ')));
    }

    public static function manifest(): ContractManifest
    {
        $source = self::source();
        $valid = new Observation(State::Complete, Reason::Valid, 1, cursor: 'opaque:+/Next== ',
            identities: ['json/opaque:notice/ 00:Ab-/42 '], titles: ['Road works'], stages: ['open'], prices: ['1250000.50']);
        $cases = [];
        foreach ([
            'valid' => ['listing.json', $valid],
            'empty' => ['listing-empty.json', new Observation(State::CompleteEmpty, Reason::Empty, terminal: TerminalProof::SemanticEmpty)],
            'truncated' => ['contract-truncated.json', new Observation(State::Partial, Reason::SchemaMismatch)],
            'overflow' => ['contract-overflow.json', new Observation(State::Partial, Reason::Overflow, 1001)],
            'wrong_total' => ['contract-wrong-total.json', new Observation(State::Partial, Reason::SchemaMismatch)],
        ] as $name => [$file, $expected]) {
            $path = self::ROOT.$file;
            $cases[] = new ContractCase('listing', $name, [$path], $expected,
                fn (): Observation => Observation::listing($source->parseListing(self::request(), ContractRuntime::response(FixtureCorpus::read($path), 'application/json'))));
        }
        $cases[] = new ContractCase('listing', 'loop', [self::ROOT.'listing.json'], new Observation(State::Partial, Reason::RepeatedPage, 1),
            fn (): Observation => Observation::listing($source->parseListing(self::request('opaque:+/Next== '), ContractRuntime::response(FixtureCorpus::read(self::ROOT.'listing.json'), 'application/json'))));
        $cases[] = new ContractCase('listing', 'identity_determinism', [self::ROOT.'listing.json'], $valid,
            function () use ($source): Observation {
                $raw = FixtureCorpus::read(self::ROOT.'listing.json');
                $page = $source->parseListing(self::request(), ContractRuntime::response($raw, 'application/json'));
                $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                $data['notices'][0] = array_reverse($data['notices'][0], true);
                $reordered = $source->parseListing(self::request(), ContractRuntime::response(json_encode($data, JSON_THROW_ON_ERROR), 'application/json'));
                $changed = $source->parseListing(self::request(), ContractRuntime::response(str_replace('Ab-/42', 'ab-/42', $raw), 'application/json'));

                return Observation::listing($page, $page->summaries->digest()->hex === $reordered->summaries->digest()->hex
                    && $page->summaries->digest(false)->hex !== $changed->summaries->digest(false)->hex);
            });
        $detail = new Observation(State::Complete, Reason::Valid, identities: $valid->identities, titles: $valid->titles, stages: $valid->stages,
            parts: ['attributes' => 'complete', 'parties' => 'complete', 'documents' => 'complete'], parties: 1, documents: 1);
        foreach (['valid', 'required_sections', 'wrong_identity', 'status', 'redaction'] as $name) {
            $expected = match ($name) {
                'required_sections' => new Observation(State::Unavailable, Reason::SchemaMismatch),
                'wrong_identity' => new Observation(State::Unavailable, Reason::IdentityMismatch),
                default => $detail,
            };
            $cases[] = new ContractCase('detail', $name, [self::ROOT.'detail.json'], $expected,
                function () use ($source, $name): Observation {
                    $body = FixtureCorpus::read(self::ROOT.'detail.json');
                    if ($name === 'wrong_identity') {
                        $body = str_replace('Ab-/42', 'ab-/42', $body);
                    }
                    if ($name === 'required_sections') {
                        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                        unset($data['participants']);
                        $body = json_encode($data, JSON_THROW_ON_ERROR);
                    }
                    $result = $source->parseDetail(self::detailRequest(), ContractRuntime::response($body, 'application/json'));
                    if ($name === 'redaction') {
                        Assert::assertStringNotContainsString('fixture-only', json_encode($result, JSON_THROW_ON_ERROR));
                        Assert::assertSame('https://files.fixture.test:443/doc/001', $result->snapshot->documents[0]->url->template());
                    }

                    return Observation::detail($result);
                });
        }

        return new ContractManifest((new JsonProcurementDefinition)->definition(), $cases, ['listing.wrong_total']);
    }
}
