<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Acquisition\Fetch\FetchBlocked;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Platform\Capabilities\SupportedDocumentAccessCapability;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\DownloadInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DocumentAccessResolver;
use App\Services\Procurement\Platform\ExecutorPlatformOutbound;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Proxyma\Caller\CallerGate;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;

/** Each operation gets its own transfer counters and the sole recorded physical-send gate. */
final readonly class RuntimeDownloadHandler implements StageHandler
{
    public function __construct(private ProfileResolver $routes, private PlatformRegistry $platforms, private DownloadPolicy $policy, private ?UrlPolicy $urls = null) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof DownloadInput) {
            throw new \InvalidArgumentException('Download input required.');
        }
        $platform = LedgerRow::one('SELECT p.id, p.code FROM prc_documents d JOIN prc_platforms p ON p.id=d.platform_id WHERE d.id=?', [$input->documentId])
            ?? throw new \LogicException('Missing document platform.');
        try {
            $routePolicy = $this->routes->profile();
        } catch (OutboundUnavailable $unavailable) {
            $outcome = $unavailable->outcome;

            return app(FetchOutcomeBridge::class)->failure(
                $outcome instanceof TransportDeferred
                    ? new FetchDeferred($outcome->reason, $outcome->retryAt)
                    : new FetchBlocked($outcome->reason));
        }
        $definition = $this->platforms->get(new PlatformCode($platform->string('code')));
        if (! $definition->documents instanceof SupportedDocumentAccessCapability) {
            return new StageBlocked(BlockedReason::HandlerNotActivated);
        }
        $access = app($definition->documents->implementation);
        if (! $access instanceof DocumentAccessResolver) {
            throw new \LogicException('Invalid registered document access resolver.');
        }
        $origins = [];
        foreach ($this->platforms->all() as $definition) {
            array_push($origins, ...$definition->sourceTargets->origins());
        }
        $outbound = new ExecutorPlatformOutbound(app(CallerOutbound::class),
            app()->makeWith(CallerGate::class, ['routes' => $this->routes->outboundRoutes($routePolicy)]),
            $this->urls ?? new UrlPolicy($origins), new TransferBudget($this->policy->maxBytes * 3, $this->policy->maxBytes * 3),
            $routePolicy, $platform->int('id'));

        return app()->makeWith(DownloadHandler::class, ['access' => $access, 'outbound' => $outbound])->execute($input, $execution);
    }
}
