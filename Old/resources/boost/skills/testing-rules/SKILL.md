---
name: testing-rules
description: >-
  Testing rules and safe DB isolation (RefreshDatabase, phpunit.xml). Activate
  when writing or running tests, or when configuring test environment.
---
# Правила тестирования

> ⚠️ **КРИТИЧЕСКИ ВАЖНО**: Всегда используйте `RefreshDatabase` в тестах! Без этого трейта тесты могут удалить данные из рабочей базы данных.

## Обязательные требования к тестированию

### 1. Изоляция тестовой базы данных

**КРИТИЧЕСКИ ВАЖНО**: Все тесты должны выполняться на отдельной тестовой базе данных, чтобы не удалялись данные рабочей базы.

#### Настройка тестовой базы данных:

1. **Предпочтительная конфигурация PHPUnit** (`phpunit.xml`) - SQLite:

    ```xml
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
    ```

    ⚠️ **ВНИМАНИЕ**: Требует установки PHP расширения `pdo_sqlite`

2. **ТЕКУЩАЯ КОНФИГУРАЦИЯ** - отдельная MySQL база ✅:

    ```xml
    <php>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="agelto_testing"/>
    </php>
    ```

    **Статус**: ✅ База `agelto_testing` создана и используется для всех тестов

    **Безопасность**: ✅ Рабочая база `agelto` полностью защищена

3. **ЗАПРЕЩЕНО** - использовать рабочую базу:
    ```xml
    <php>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="agelto"/> <!-- ❌ НИКОГДА НЕ ДЕЛАЙТЕ ТАК -->
    </php>
    ```

#### Проверка перед запуском тестов:

```bash
# 1. Проверить конфигурацию phpunit.xml
cat phpunit.xml | grep -A 1 "DB_"
# Должно вывести:
# <env name="DB_CONNECTION" value="mysql"/>
# <env name="DB_DATABASE" value="agelto_testing"/>

# 2. Проверить наличие тестовой базы
php artisan tinker --execute="DB::select('SHOW DATABASES LIKE \"agelto_testing\"');"

# 3. Запустить тесты
php artisan test
```

**Критические проверки:**

- ✅ Убедиться, что `DB_CONNECTION` настроен правильно в `phpunit.xml`
- ✅ Убедиться, что `DB_DATABASE` = `agelto_testing` (НЕ `agelto`)
- ✅ Убедиться, что `RefreshDatabase` используется во всех тестах
- ✅ Проверить, что тесты не влияют на рабочую базу данных
- ✅ Никогда не запускать тесты без `RefreshDatabase`

### 2. Использование RefreshDatabase

Все тесты, которые работают с базой данных, должны использовать трейт `RefreshDatabase`:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    // тесты...
}
```

**⚠️ ВАЖНО**: `RefreshDatabase` **ПОЛНОСТЬЮ ОЧИЩАЕТ** все таблицы в базе данных после каждого теста. Это означает, что:

- Все данные в базе будут удалены
- Это нормальное поведение для тестов
- Не храните важные данные в базе во время разработки
- Всегда используйте фабрики для создания тестовых данных

### 3. Фабрики для создания тестовых данных

- Использовать фабрики моделей для создания тестовых данных
- Не создавать данные напрямую в тестах
- Использовать `User::factory()->create()` вместо `User::create()`

### 4. Очистка после тестов

- `RefreshDatabase` автоматически очищает базу после каждого теста
- Не использовать `DB::table()->truncate()` вручную
- Не удалять данные из рабочей базы

### 5. Проверка окружения

Перед запуском тестов всегда проверять:

```bash
# Проверить, что используется тестовая база
php artisan tinker --env=testing
>>> config('database.default') // должно быть 'sqlite'
>>> config('database.connections.sqlite.database') // должно быть ':memory:' или 'database/testing.sqlite'
```

### 6. Команды для безопасного тестирования

```bash
# Запуск тестов (автоматически использует тестовую базу)
php artisan test

# Запуск конкретного теста
php artisan test --filter=TestName

# Запуск с очисткой кэша
php artisan test --env=testing
```

### 7. Запрещенные действия

❌ **НИКОГДА НЕ ДЕЛАЙТЕ**:

- Не запускайте тесты на рабочей базе данных
- Не используйте `DB_CONNECTION=mysql` в тестах
- Не создавайте данные в рабочей базе через тесты
- Не используйте `tinker` для тестирования на рабочей базе

✅ **ВСЕГДА ДЕЛАЙТЕ**:

- Используйте SQLite для тестов
- Используйте `RefreshDatabase`
- Используйте фабрики для создания данных
- Проверяйте конфигурацию перед запуском тестов

## Пример правильного теста

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Blog\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;

test('can create post with tags', function () {
    // RefreshDatabase автоматически очистит базу после теста
    $user = User::factory()->create();

    $post = Post::factory()->create([
        'author_id' => $user->id,
    ]);

    // Тест работает с тестовой базой SQLite
    expect($post->author_id)->toBe($user->id);
});
```

## Настройка для CI/CD

В CI/CD окружении также используйте тестовую базу:

```yaml
# .github/workflows/tests.yml
- name: Run tests
  run: |
      cp .env.testing .env
      php artisan test
```

## Мониторинг

Регулярно проверяйте:

- Что тесты не влияют на рабочую базу
- Что используется правильная конфигурация
- Что `RefreshDatabase` работает корректно

## Рекомендации по работе с тестами

### Перед запуском тестов:

1. **Сделайте резервную копию** важных данных
2. **Убедитесь**, что `RefreshDatabase` настроен
3. **Проверьте**, что тесты используют фабрики

### После запуска тестов:

1. **Восстановите** тестовые данные если нужно
2. **Проверьте**, что рабочая база не повреждена
3. **Запустите** миграции если нужно

### Если данные были удалены:

```bash
# Восстановите тестовые данные
php artisan tinker

# Или запустите сидеры
php artisan db:seed
```

---

## Текущий статус проекта (обновлено 2025-10-31)

### ✅ Конфигурация безопасности

**Файл**: `phpunit.xml`

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="agelto_testing"/>
```

**Статус**:

- ✅ Тестовая база: `agelto_testing` (изолирована)
- ✅ Рабочая база: `agelto` (защищена)
- ✅ RefreshDatabase: Настроен глобально в `tests/Pest.php`
- ✅ Все тесты проходят успешно

**Проверено**:

- ✅ TasksControllerTest: 17/17 тестов пройдено
- ✅ Рабочая база не затронута (7998 users, 1 company)
- ✅ Тесты изолированы и безопасны

### 📋 Быстрая проверка

```bash
# Проверить конфигурацию
grep "DB_DATABASE" phpunit.xml
# Должно вывести: <env name="DB_DATABASE" value="agelto_testing"/>

# Запустить тесты
php artisan test --filter=TasksControllerTest
```

### 🚨 История проблем

**Проблема (2025-10-31)**:

- ❌ В `phpunit.xml` отсутствовал `DB_CONNECTION`
- ❌ Тесты использовали рабочую базу `agelto`
- ❌ Данные были потеряны при запуске тестов

**Решение**:

- ✅ Добавлен `DB_CONNECTION=mysql` в `phpunit.xml`
- ✅ Явно указана `DB_DATABASE=agelto_testing`
- ✅ Добавлен `RefreshDatabase` в тесты
- ✅ Проверена изоляция баз данных

**Результат**: Рабочая база полностью защищена. Тесты безопасны.

---

**Помните**: `RefreshDatabase` очищает ВСЕ данные в **ТЕСТОВОЙ** базе. Это нормально и безопасно, так как используется отдельная база `agelto_testing`.
