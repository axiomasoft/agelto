<?php

declare(strict_types=1);

namespace App\Console\Platforms\Eis;

use App\Dto\Procurement\Network\Http\HttpRequestPayload;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\Card\DocumentsParser;
use App\Services\Platforms\Eis\Card\EisCardUrlBuilder;
use App\Services\Platforms\Eis\Diagnostics\DocumentUidHtmlParser;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;
use App\Support\Time\Monotonic;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Attribute\AsCommand;

#[Description('Performs the owner-authorized bounded B2 EIS attachment uid verification')]
#[AsCommand(name: 'crawler:verify-document-uid')]
#[Signature('crawler:verify-document-uid {--input= : Authorized P4 scope JSON} {--output= : Sanitized verification JSON}')]
final class VerifyDocumentUid extends Command
{
    public function handle(
        EisCardUrlBuilder $urls,
        DocumentsParser $documents,
        ProfileResolver $proxies,
        LegacyOutbound $outbound,
        DocumentUidHtmlParser $parser = new DocumentUidHtmlParser,
    ): int {
        try {
            $carriers = $parser->loadCarriers($parser->resolvePathOption($this->option('input'), 'input'));
            $registry = $this->registry();
            $templates = $parser->extractTemplateTypes(is_array($registry->settings) ? $registry->settings : null);
            $numbers = $carriers['numbers'];
            $ceiling = $carriers['ceiling'];
            $intervalMs = $carriers['interval_ms'];
            $maxErrors = $carriers['max_errors'];
            $a1MaxPages = $carriers['a1_max_pages'];

            if ($a1MaxPages + (count($numbers) * count($templates)) > $ceiling) {
                throw new \RuntimeException('Authorized A1+B2 worst-case requests exceed the total request ceiling.');
            }

            $captures = [];
            $evidenceIds = [];
            $requestCount = 0;
            $consecutiveErrors = 0;
            $stopReasons = [];
            $stop = false;

            foreach ($numbers as $registryNumber) {
                foreach ($templates as $procedureType) {
                    if ($requestCount >= $ceiling - $a1MaxPages) {
                        $stopReasons[] = 'request-ceiling-reached';
                        $stop = true;

                        break;
                    }

                    if ($requestCount > 0) {
                        Sleep::for($intervalMs)->milliseconds();
                    }

                    $url = $urls->buildUrl(registry: $registry->code, procedureUrlType: $procedureType, tab: 'documents', registryNumber: $registryNumber);

                    if ($url === null) {
                        continue;
                    }

                    $requestCount++;
                    $evidenceId = sprintf('b2-card-%s-%02d', $registryNumber, $requestCount);

                    try {
                        [$response, $metadata] = $this->request(registry: $registry, url: $url, proxies: $proxies, outbound: $outbound);
                        $consecutiveErrors = $response->serverError() ? $consecutiveErrors + 1 : 0;
                        $collected = $parser->collectFromHtml($documents, $response->body(), $registryNumber, $response->successful());

                        $captures[] = $parser->buildCapture(
                            evidenceId: $evidenceId,
                            registryNumber: $registryNumber,
                            procedureType: $procedureType,
                            url: $url,
                            httpStatus: $response->status(),
                            metadata: $metadata,
                            validators: [
                                'etag' => $response->header('ETag'),
                                'last_modified' => $response->header('Last-Modified'),
                            ],
                            snapshotStatus: $collected['snapshot_status'],
                            documents: $collected['documents'],
                        );
                        $evidenceIds[] = $evidenceId;

                        if ($response->status() === 429 && trim((string) $response->header('Retry-After')) === '') {
                            $stopReasons[] = 'http-429-without-retry-window';
                            $stop = true;

                            break;
                        }

                        if ($consecutiveErrors >= $maxErrors) {
                            $stopReasons[] = 'three-consecutive-transport-errors';
                            $stop = true;

                            break;
                        }

                        if ($collected['matched']) {
                            break;
                        }
                    } catch (OutboundUnavailable $exception) {
                        $consecutiveErrors++;
                        $captures[] = $parser->buildTransportErrorCapture(
                            evidenceId: $evidenceId,
                            registryNumber: $registryNumber,
                            procedureType: $procedureType,
                            url: $url,
                            transportError: $exception->outcome->reason->value,
                        );
                        $evidenceIds[] = $evidenceId;

                        if ($consecutiveErrors >= $maxErrors) {
                            $stopReasons[] = 'three-consecutive-transport-errors';
                            $stop = true;

                            break;
                        }
                    }
                }

                if ($stop) {
                    break;
                }
            }

            $artifact = $parser->buildVerdict(
                authoritySha: $carriers['authority_sha'],
                scopeSha: $carriers['scope_sha'],
                requestCount: $requestCount,
                captures: $captures,
                stopReasons: $stopReasons,
                evidenceIds: $evidenceIds,
            );

            $parser->writeArtifact($parser->resolvePathOption($this->option('output'), 'output'), $artifact);
            $this->info(sprintf('B2 verification complete: %d request(s), branch=%s.', $requestCount, $artifact['branch']));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{0: Response, 1: array{duration_ms: int, wait_ms: int, proxy_source: string|null}} */
    private function request(Registry $registry, string $url, ProfileResolver $proxies, LegacyOutbound $outbound): array
    {
        $platform = $registry->platform()->firstOrFail();
        $startedAt = Monotonic::nanoseconds();
        $platformCode = $platform->getAttribute('code');
        if (! is_string($platformCode)) {
            throw new \LogicException('Platform code is unavailable.');
        }
        $response = $outbound->request(callerCode: 'legacy.uid_verification', platformId: $platform->id,
            providerKey: null, capability: 'detail', url: new SafeUrl($url),
            trafficClass: TrafficClass::Diagnostic, routePolicy: $proxies->profile(),
            controls: ['system', 'acquisition', 'acquisition:platform:'.$platformCode], deadlineSeconds: 90,
            payload: new HttpRequestPayload([
                'user-agent' => config()->string('outbound.http.user_agent'), 'referer' => 'https://zakupki.gov.ru/',
            ]), sendLimit: 1);

        return [$response, ['duration_ms' => (int) ((Monotonic::nanoseconds() - $startedAt) / 1_000_000),
            'wait_ms' => 0, 'proxy_source' => null]];
    }

    private function registry(): Registry
    {
        return Registry::query()
            ->where('code', 'fz44')
            ->whereHas('platform', static fn ($query) => $query->where('code', 'eis'))
            ->firstOrFail();
    }
}
