<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use RuntimeException;

/** Неверная секция `config('crawler.source_control')`; сообщение содержит путь к ошибке. */
final class SourceControlConfigurationException extends RuntimeException
{
    public static function at(string $path, string $problem): self
    {
        return new self("crawler.source_control.{$path}: {$problem}");
    }
}
