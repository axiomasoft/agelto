<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

/**
 * Опциональная валидация type_profile его анализатором. Ядро проверяет instanceof;
 * отсутствие capability означает отсутствие дополнительной проверки.
 *
 * Метод получает сырой массив и возвращает все ошибки сразу: ключ — поле либо пустая
 * строка для профиля целиком, значение — список готовых сообщений. Пустой массив означает
 * валидность; ошибки данных возвращаются без исключения.
 */
interface SupportsTypeProfileValidation
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, list<string>>
     */
    public function validateTypeProfile(array $profile): array;
}
