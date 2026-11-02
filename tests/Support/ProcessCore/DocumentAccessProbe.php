<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Document\DocumentFetchPlan;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Platform\AssessmentReason as Reason;
use App\Enums\Procurement\Platform\Completeness as State;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\Contracts\DocumentAccessResolver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\SemanticObservation as Observation;

/** Common persisted document-access contract fixture; adapters only declare the capability. */
final readonly class DocumentAccessProbe
{
    public static function observe(PlatformDefinition $definition, string $url, bool $refresh): Observation
    {
        if (! DB::table('prc_platforms')->where('code', $definition->code->value)->exists()) {
            NetworkFixtures::platform($definition->code->value);
        }
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        $platformId = DB::table('prc_platforms')->where('code', $definition->code->value)->sole()->id;
        $document = Document::factory()->create(['platform_id' => $platformId, 'source_document_key' => 'fixture:'.LedgerFixtures::uuid()->value,
            'source_key_version' => 1, 'access_descriptor_version' => 1, 'reference_observed_at' => now(), 'access_schema_version' => 1,
            'access_descriptor' => json_encode(['url' => $url, 'expires_at' => null], JSON_THROW_ON_ERROR)]);
        $intent = LedgerFixtures::uuid();
        $access = LedgerFixtures::hash('contract access');
        app(WorkStoreRepository::class)->storeIntent($intent, LedgerFixtures::hash($intent->value), StageCode::Download,
            new SubjectKey(SubjectKind::Document, $document->id), LedgerFixtures::hash('contract semantic'), $access, 1, 1, IntentState::Active);
        $resolver = app($definition->documents->implementation);
        Assert::assertInstanceOf(DocumentAccessResolver::class, $resolver);
        $result = $resolver->resolve(new DownloadInput($document->id, $intent, 1, $access), ContractRuntime::context(), $refresh);
        if ($refresh) {
            Assert::assertInstanceOf(FetchBlocked::class, $result);
            Assert::assertSame(NetworkBlockedReason::PolicyReview, $result->reason);

            return new Observation(State::Unavailable, Reason::InvalidResponse);
        }
        Assert::assertInstanceOf(DocumentFetchPlan::class, $result);
        Assert::assertSame($url, $result->request->url->reveal());
        Assert::assertSame(TrafficClass::Download, $result->request->trafficClass);

        return new Observation(State::Complete, Reason::Valid);
    }
}
