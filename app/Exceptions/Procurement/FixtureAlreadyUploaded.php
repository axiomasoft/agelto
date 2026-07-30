<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

/**
 * Дубль по sha256 при загрузке эталона (`FixtureUploader`, P5.1, D133): файл уже загружен под
 * другим кодом — вторая запись под новым кодом удвоила бы эталон в оценке качества и сломала бы
 * учёт корпуса, поэтому загрузка ОТКАЗЫВАЕТ, а не создаёт вторую запись (Implementation Rule 4).
 */
final class FixtureAlreadyUploaded extends OperationFailed
{
    public function __construct(string $existingCode)
    {
        parent::__construct("Этот файл уже загружен как {$existingCode}.");
    }
}
