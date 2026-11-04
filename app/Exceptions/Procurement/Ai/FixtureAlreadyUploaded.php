<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use App\Exceptions\Procurement\OperationFailed;

/**
 * Дубль по sha256 при загрузке эталона (`FixtureUploader`): файл уже загружен под
 * другим кодом — вторая запись под новым кодом удвоила бы эталон в оценке качества и сломала бы
 * учёт корпуса, поэтому загрузка ОТКАЗЫВАЕТ, а не создаёт вторую запись.
 */
final class FixtureAlreadyUploaded extends OperationFailed
{
    public function __construct(string $existingCode)
    {
        parent::__construct("Этот файл уже загружен как {$existingCode}.");
    }
}
