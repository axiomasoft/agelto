<?php

declare(strict_types=1);

namespace App\Services\Reference;

use App\Models\Reference\Kbk;
use App\Models\Reference\Ktru;
use App\Models\Reference\Okpd2;
use App\Models\Reference\Okved2;
use Illuminate\Database\Eloquent\Model;

/**
 * Резолв сырого кода классификатора → FK на `ref_*` (read-only). Отсутствие кода
 * в справочнике — `null`, не исключение (`01-database.md` §0 п.10: позиция всегда
 * хранит сырой код, FK — опциональное дополнение).
 */
final readonly class ClassifierResolver
{
    public function resolveOkpd2(?string $code): ?int
    {
        return $this->resolve(modelClass: Okpd2::class, code: $code);
    }

    public function resolveKtru(?string $code): ?int
    {
        return $this->resolve(modelClass: Ktru::class, code: $code);
    }

    public function resolveOkved2(?string $code): ?int
    {
        return $this->resolve(modelClass: Okved2::class, code: $code);
    }

    public function resolveKbk(?string $code): ?int
    {
        return $this->resolve(modelClass: Kbk::class, code: $code);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function resolve(string $modelClass, ?string $code): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        /** @var int|null $id */
        $id = $modelClass::query()->where('code', $code)->value('id');

        return $id;
    }
}
