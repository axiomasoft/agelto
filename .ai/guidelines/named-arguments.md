# Named Arguments

Все вызовы методов и функций в PHP должны использовать именованные аргументы.

```php
// ✅ Правильно
$user = User::query()->updateOrCreate(
    attributes: ['email' => $email],
    values: ['name' => $name],
);

$link = Link::query()->firstOrCreate(
    attributes: ['reg_number' => $regNumber],
    values: ['status' => LinkStatus::Pending],
);
```

```php
// ❌ Неправильно
$user = User::query()->updateOrCreate(['email' => $email], ['name' => $name]);
```

Применяется ко всем вызовам: методы объектов, статические методы, Eloquent, Laravel-хелперы.

Исключения: встроенные PHP-функции с одним аргументом, где именование не добавляет ясности.
