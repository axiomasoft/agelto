---
name: php-coding-standards
description: >-
  PHP coding standards (strict types, final classes, named args, PHPDoc, Pint).
  Activate when writing or reviewing PHP, or when user asks about code style.
---
# PHP Coding Standards

## Обязательные правила для всех PHP файлов

### 1. Именованные аргументы
Все вызовы методов и функций должны использовать именованные аргументы.

### 2. Строгая типизация
Все PHP файлы должны начинаться с `declare(strict_types=1);`

### 3. Финальные классы
Все классы должны быть объявлены как `final` (кроме исключений)

### 4. Типизация методов
Все методы должны иметь явные типы возвращаемых значений и параметров

### 5. PHPDoc
Все публичные методы должны иметь PHPDoc комментарии

### 6. Форматирование
Использовать Laravel Pint для форматирования кода

### Примеры:

```php
<?php

declare(strict_types=1);

namespace App\Models;

final class Example
{
    /**
     * Create a new instance
     */
    public function __construct(
        public string $name,
        public int $age
    ) {
    }

    /**
     * Get user initials
     */
    public function getInitials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Update user data
     */
    public function updateData(array $data): static
    {
        $this->updateOrCreate(
            attributes: ['id' => $this->id],
            values: $data
        );

        return $this;
    }
}
```
