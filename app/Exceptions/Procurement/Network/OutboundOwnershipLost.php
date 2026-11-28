<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Network;

/** Expected control exit; never an item or handler failure. */
final class OutboundOwnershipLost extends \RuntimeException {}
