<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Control;

use App\Enums\Procurement\Network\NetworkChangeStatus;

/** policy_version строки после locked проверки; null — строка не найдена. */
final readonly class NetworkChangeResult
{
    public function __construct(public NetworkChangeStatus $status, public ?int $policyVersion) {}
}
