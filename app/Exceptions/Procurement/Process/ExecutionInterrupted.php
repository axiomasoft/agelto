<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/**
 * Управляющий выход handler на кооперативной точке: не дефект кода.
 * WorkRunner ловит эти типы до generic Throwable, поэтому они не пишут fault_signature и не
 * участвуют в handler hold. Сообщение без payload и secrets.
 */
abstract class ExecutionInterrupted extends \RuntimeException {}
