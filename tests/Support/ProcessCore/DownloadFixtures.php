<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Document\DocumentFetchPlan;
use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Document\DownloadFinalizer;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\DocumentAccessResolver;
use App\Services\Procurement\Document\DownloadHandler;
use App\Services\Procurement\Document\DownloadStageCodec;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\ExecutorPlatformOutbound;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;

final class DownloadFixtures
{
    public readonly PermitFixtures $network;

    public readonly Document $document;

    public readonly DocumentAccessResolver $access;

    public function __construct(public readonly WireFixture $wire, public string $path = '/document', public ?ContentHash $expectedHash = null)
    {
        ExecutionFixtures::authority([StageCode::Download]);
        app(StageRegistry::class)->bindFinalization(StageCode::Download, new DownloadStageCodec, app(DownloadFinalizer::class));
        $this->network = new PermitFixtures($wire);
        $this->document = Document::factory()->create(['source_url' => null, 'source_document_key' => 'document:'.bin2hex(random_bytes(6)),
            'source_key_version' => 1, 'access_schema_version' => 1, 'access_descriptor_version' => 1,
            'reference_observed_at' => now(), 'access_descriptor' => json_encode(['url' => $wire->url($path)->reveal(), 'expires_at' => null], JSON_THROW_ON_ERROR)]);
        $this->access = new class($this) implements DocumentAccessResolver
        {
            public int $refreshes = 0;

            public function __construct(private DownloadFixtures $fixture) {}

            public function resolve(DownloadInput $input, ExecutionContext $execution, bool $refresh = false): DocumentFetchPlan
            {
                $this->refreshes += (int) $refresh;

                return $this->fixture->plan($refresh ? '/refreshed' : $this->fixture->path);
            }
        };
        $domain = DocumentStorageFixtures::domain(1000000, 'download');
        $temporary = DocumentStorageFixtures::domain(1000000, 'download-tmp');
        config()->set('documents.download', ['max_bytes' => 65536, 'revalidation_seconds' => 60,
            'durable_domain' => DB::table('prc_storage_domains')->where('id', $domain->value)->value('domain_key'),
            'temporary_domain' => DB::table('prc_storage_domains')->where('id', $temporary->value)->value('domain_key'),
            'disk' => 'local', 'temporary_disk' => 'local']);
        $this->installProfile();
    }

    public function plan(string $path): DocumentFetchPlan
    {
        $profile = TransportFixtures::profile(['response_kind' => 'binary', 'max_text_bytes' => null, 'charset_default' => null]);

        return new DocumentFetchPlan(new OutboundRequestPlan(LedgerFixtures::uuid(), $this->network->target, 'GET', $this->wire->url($path),
            $profile, TrafficClass::Download, IdempotencyClass::ReadOnly), 'fixture.pdf', $this->expectedHash);
    }

    private function installProfile(): void
    {
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($this->network->code, [
            new SourceTargetDefinition('card', 'download', $this->network->url->origin, [$this->plan($this->path)->request->profile], new OriginBudgetPolicy(600, 1, 2)),
        ]));
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100000]);
    }

    /** @return array{DownloadInput,WorkId,ExecutionContext} */
    public function running(?DocumentHead $head = null, ?ProcessUuid $intent = null): array
    {
        $head ??= new DocumentHead($this->document->id, 1, null);
        $intent ??= LedgerFixtures::uuid();
        $input = new DownloadInput($this->document->id, $intent, $head->generation, LedgerFixtures::hash('access'));
        $work = new WorkId(LedgerFixtures::uuid());
        DB::transaction(function () use ($input, $intent, $work): void {
            $works = app(WorkStoreRepository::class);
            if (! DB::table('prc_operation_intents')->where('id', $intent->value)->exists()) {
                $cycle = DB::table('prc_documents')->where('id', $input->documentId)->value('acquisition_cycle_seq') + 1;
                $works->storeIntent($intent, ContentHash::of('document-acquisition:'.$input->documentId.':'.$cycle), StageCode::Download,
                    new SubjectKey(SubjectKind::Document, $input->documentId), LedgerFixtures::hash('semantic'), $input->accessPolicyHash,
                    $cycle, $input->expectedDocumentHeadGeneration, IntentState::Active);
                DB::table('prc_documents')->where('id', $input->documentId)->update(['acquisition_cycle_seq' => $cycle]);
            } else {
                DB::table('prc_operation_intents')->where('id', $intent->value)->update(['state' => 'active', 'pending_reason' => null]);
            }
            $works->storeWork($work, $input, 1, $intent, 'manual', null, null, 100, LedgerFixtures::uuid(), LedgerFixtures::uuid());
        });
        ExecutionFixtures::ensureWorkScopes(StageCode::Download, 'manual');
        $lease = FinalizeFixtures::running($work);

        return [$input, $work, new ExecutionContext($lease, app(WorkStoreRepository::class), app(ProcessClock::class),
            Duration::seconds(300), Duration::seconds(420), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30))];
    }

    public function handler(?OutboundAttemptGate $gate = null): DownloadHandler
    {
        return app()->makeWith(DownloadHandler::class, ['access' => $this->access, 'outbound' => new ExecutorPlatformOutbound(
            app(OutboundExecutor::class), $gate ?? $this->network->gate(), $this->wire->policy(), new TransferBudget(262144, 262144))]);
    }
}
