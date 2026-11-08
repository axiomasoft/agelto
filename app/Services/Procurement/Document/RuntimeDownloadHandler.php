<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SupportedDocumentAccessCapability;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\ScheduledAttemptGate;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DocumentAccessResolver;
use App\Services\Procurement\Platform\ExecutorPlatformOutbound;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Proxy\ProxyResolver;

/** Each operation gets its own transfer counters and the sole recorded physical-send gate. */
final readonly class RuntimeDownloadHandler implements StageHandler
{
    public function __construct(private ProxyResolver $routes, private PlatformRegistry $platforms, private DownloadPolicy $policy, private ?UrlPolicy $urls = null) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof DownloadInput) {
            throw new \InvalidArgumentException('Download input required.');
        }
        $platform = LedgerRow::one('SELECT p.id, p.code FROM prc_documents d JOIN prc_platforms p ON p.id=d.platform_id WHERE d.id=?', [$input->documentId])
            ?? throw new \LogicException('Missing document platform.');
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
        try {
            $routePolicy = $this->routes->profile();
        } catch (OutboundUnavailable $unavailable) {
            $outcome = $unavailable->outcome;

            return app(FetchOutcomeBridge::class)->failure(
                $outcome instanceof TransportDeferred
                    ? new FetchDeferred($outcome->reason, $outcome->retryAt)
                    : new FetchBlocked($outcome->reason));
        }
        $outbound = new ExecutorPlatformOutbound(app(OutboundExecutor::class),
            app()->makeWith(ScheduledAttemptGate::class, ['routes' => $this->routes->outboundRoutes($routePolicy)]),
            $this->urls ?? new UrlPolicy($origins), new TransferBudget($this->policy->maxBytes * 3, $this->policy->maxBytes * 3),
            $routePolicy, $platform->int('id'));

        return app()->makeWith(DownloadHandler::class, ['access' => $access, 'outbound' => $outbound])->execute($input, $execution);
    }
}
