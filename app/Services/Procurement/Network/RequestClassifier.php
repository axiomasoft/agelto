<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\HealthClassification;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Enums\Procurement\Network\HealthSignal as S;
use App\Enums\Procurement\Network\RouteKind;
use App\Enums\Procurement\Network\TransportFailure as F;

/** Attribution uses typed transport phase evidence, never exception message matching. */
final readonly class RequestClassifier
{
    public function classify(HttpAttemptResult $result, RouteKind $route): HealthClassification
    {
        $failure = $result->failure;
        if ($route === RouteKind::Proxy && ($failure === F::ProxyAuth || $result->response?->status === 407)) {
            return new HealthClassification(S::CredentialHold, S::Ignored, S::Ignored, 'credential_hold');
        }
        if ($route === RouteKind::Proxy && $failure === F::ProxyConnect) {
            return new HealthClassification(S::Failure, S::Ignored, S::Ignored, 'proxy_connect');
        }
        if (in_array($failure, [F::Connect, F::Timeout, F::Truncated], true)) {
            return new HealthClassification(S::Ignored, S::Failure, S::Failure, $failure->value);
        }
        if ($failure !== null) {
            return new HealthClassification(S::Ignored, S::Ignored, S::Ignored, $failure->value);
        }
        $status = $result->response?->status;
        if ($status === 429 || $status === 407) {
            return new HealthClassification(S::Ignored, S::Ignored, S::Ignored, 'http_'.$status);
        }

        return new HealthClassification(S::Success, S::Success,
            in_array($status, [502, 503, 504], true) ? S::Failure : S::Success, 'http_'.$status);
    }
}
