<?php

declare(strict_types=1);

namespace App\Exceptions\Llm;

final class UnsupportedConsumer extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This caller has no registered financial and network owner. Use the durable analysis workflow.');
    }
}
