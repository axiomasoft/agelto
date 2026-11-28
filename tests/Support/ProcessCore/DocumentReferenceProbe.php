<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Dto\Procurement\Document\Download\DocumentReference;
use App\Dto\Procurement\Document\Download\SourceDocumentKey;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentCatalogRepository;
use App\Repositories\Procurement\Document\DocumentReferenceLocks;
use Illuminate\Support\Facades\DB;

/** Test adapter translating the retained HTML parser corpus to the canonical fenced reference writer. */
final readonly class DocumentReferenceProbe
{
    public function index(Procurement $procurement, DocumentsSnapshot $snapshot): void
    {
        $identity = new SourceIdentity(new PlatformCode($procurement->registry->platform->code),
            new SourceIdentifierType($procurement->source_identity_type), new SourceIdentifierValue($procurement->source_identity_value));
        $refs = [];
        foreach ($snapshot->documents as $document) {
            $key = $document->externalId ?? hash('sha256', $document->sourceUrl);
            $refs[] = new DocumentReference($identity, $key, $document->title, new SafeUrl($document->sourceUrl),
                new SourceDocumentKey($procurement->source_identity_value.':'.$key, 1));
        }
        DB::transaction(function () use ($procurement, $snapshot, $refs): void {
            $locks = new DocumentReferenceLocks($procurement->platform_id, $procurement->id, $refs);
            $locks->lockIdentities();
            $locks->lockSubjects();
            $catalog = app(DocumentCatalogRepository::class);
            $ids = [];
            foreach ($refs as $ref) {
                $ids[] = $catalog->upsertReference($procurement, $ref, LedgerFixtures::hash('fixture access'))->reference->value;
            }
            if ($snapshot->authorizesReconciliation()) {
                $catalog->reconcile($procurement->id, $ids);
            }
        });
    }
}
