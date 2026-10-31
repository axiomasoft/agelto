<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\SnapshotPart;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\RunKind;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\DetailFinalizer;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\StatusFinalizer;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Acquisition\DetailStageCodec;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Acquisition\IdentityResolver;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Acquisition\StatusStageCodec;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\Procurement\SnapshotFactory;

final class DetailFixtures
{
    public static function bind(): void
    {
        ExecutionFixtures::authority([StageCode::Detail, StageCode::StatusRefresh]);
        self::bindLocal();
    }

    public static function bindLocal(): void
    {
        $codec = new DetailValueCodec(EisDetailExtension::class);
        app()->instance(DetailValueCodec::class, $codec);
        foreach ([StageCode::Detail, StageCode::StatusRefresh] as $stage) {
            ExecutionFixtures::registerHandler($stage);
        }
        app(StageRegistry::class)->bindFinalization(StageCode::Detail, new DetailStageCodec($codec), app(DetailFinalizer::class));
        app(StageRegistry::class)->bindFinalization(StageCode::StatusRefresh, new StatusStageCodec($codec), app(StatusFinalizer::class));
    }

    public static function input(WorkId $work): WorkInput
    {
        $row = LedgerRow::one('SELECT input_payload::text AS payload FROM prc_process_work WHERE id=?', [$work->value->value])
            ?? throw new \LogicException('Missing fixture work');

        return (new ProcessPayloadCodec)->decodeInput(new VersionedPayload($row->string('payload'), 1));
    }

    /** @return array{p: Procurement, input: DetailInput, work: WorkId, request: DetailRequest} */
    public static function work(?Registry $registry = null, string $value = 'detail-fixture', string $stage = 'Подача заявок', string $adapterVersion = 'fixture-detail/1'): array
    {
        $registry ??= Registry::factory()->eis()->create();
        $p = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, ['registryNumber' => $value, 'sourceStage' => $stage]));
        $intent = LedgerFixtures::uuid();
        $input = new DetailInput($p->id, $intent, $p->head_generation, ContentHash::of('semantic'), ContentHash::of('access'), $adapterVersion);
        $request = new DetailRequest('fz44', app(IdentityResolver::class)->forRegistry($registry->id, $value)->source, new CursorEnvelope('eis.card', 1, 'ea20'));
        $work = DB::transaction(function () use ($p, $intent, $input, $request): WorkId {
            app(WorkStoreRepository::class)->storeIntent($intent, ContentHash::of($intent->value), StageCode::Detail, new SubjectKey(SubjectKind::Procurement, $p->id),
                $input->semanticPolicyHash, $input->accessPolicyHash, 1, $p->head_generation, IntentState::Active);
            app(DetailSnapshotRepository::class)->saveRequest($p->id, $p->head_generation, $intent, $request);

            $run = LedgerFixtures::uuid();
            app(RunStoreRepository::class)->storeRun($run,
                RunKind::Manual, null, null,
                new VersionedPayload('{"fixture":"detail"}', 1), null, LedgerFixtures::uuid(), LedgerFixtures::uuid());

            return LedgerFixtures::storeWork($input, $intent, $run);
        });

        return compact('p', 'input', 'work', 'request');
    }

    public static function snapshot(DetailRequest $request, string $part, Completeness $complete = Completeness::Complete, string $body = 'A', ?string $revision = null): ProcurementSnapshot
    {
        return new ProcurementSnapshot($request->identity, 'Поставка бумаги офисной', 'Подача заявок', [], [], [], [],
            [new SnapshotPart($part, $complete, true, ContentHash::of($body), new \DateTimeImmutable('2026-01-01'))], sourceRevision: $revision);
    }
}
