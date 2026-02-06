---
name: named-arguments
description: >-
  Mandatory use of named arguments in PHP. Activate when writing or reviewing
  PHP method/function calls, or when user mentions named arguments or readability.
---
# Named Arguments Rule

## Обязательное использование именованных аргументов

Все PHP файлы в проекте должны использовать именованные аргументы для вызовов методов и функций.

### Примеры правильного использования:

```php
// ✅ Правильно - с именованными аргументами
$user = User::query()->updateOrCreate(
    attributes: ['email' => $email],
    values: ['name' => $name, 'password' => $password]
);

$user->assignRole(role: 'admin');

$collection = $this->addMediaCollection(name: 'avatar')
    ->acceptsMimeTypes(mimeTypes: ['image/jpeg', 'image/png'])
    ->singleFile();

$conversion = $this->addMediaConversion(name: 'thumb')
    ->width(width: 150)
    ->height(height: 150)
    ->sharpen(amount: 10);

$panel->discoverResources(
    in: app_path('Filament/Resources'),
    for: 'App\Filament\Resources'
);
```

### Примеры неправильного использования:

```php
// ❌ Неправильно - без именованных аргументов
$user = User::query()->updateOrCreate(['email' => $email], ['name' => $name]);
$user->assignRole('admin');
$collection = $this->addMediaCollection('avatar');
$conversion = $this->addMediaConversion('thumb')->width(150);
```

### Применяется к:

- Все вызовы методов объектов
- Все вызовы статических методов
- Все вызовы функций Laravel (config, app, etc.)
- Все вызовы методов Eloquent
- Все вызовы методов Filament
- Все вызовы методов Media Library
- Все вызовы методов Spatie Permission

### Исключения:

- Встроенные PHP функции (array_map, array_filter, etc.) - по желанию
- Простые вызовы с одним аргументом (если это не ухудшает читаемость)

### Преимущества:

1. **Читаемость**: Код становится более понятным
2. **Безопасность**: Меньше ошибок при изменении порядка параметров
3. **Поддержка**: Легче поддерживать и рефакторить код
4. **Совместимость**: Совместимость с будущими версиями PHP
