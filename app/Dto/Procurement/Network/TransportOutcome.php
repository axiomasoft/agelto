<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/**
 * Закрытый результат OutboundExecutor: ровно три варианта —
 * TransportSucceeded, TransportDeferred, TransportBlocked. ConnectionException не пересекает эту
 * границу: executor превращает её в typed deferred outcome; parser defect остаётся defect.
 */
interface TransportOutcome {}
