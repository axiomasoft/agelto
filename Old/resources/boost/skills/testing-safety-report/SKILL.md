---
name: testing-safety-report
description: >-
  Report on safe testing setup and RefreshDatabase. Activate when configuring
  or verifying test safety, or when user asks about test database isolation.
---
# Отчет о настройке безопасного тестирования

## ✅ Выполненные изменения

### 1. Настройка изолированной тестовой базы данных

**Файл: `phpunit.xml`**

Изменена конфигурация для использования отдельной тестовой базы:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="agelto_testing"/>
```

**До изменений:**

- ❌ Отсутствовал `DB_CONNECTION` → использовалась база из `.env`
- ❌ База `agelto_testing` была указана, но не использовалась правильно
- ⚠️ **РИСК**: Тесты работали с рабочей базой данных `agelto`

**После изменений:**

- ✅ Явно указано `DB_CONNECTION=mysql` для тестов
- ✅ Используется отдельная база `agelto_testing`
- ✅ Рабочая база `agelto` защищена от изменений

### 2. Добавлен трейт RefreshDatabase в тест

**Файл: `tests/Feature/TasksControllerTest.php`**

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
```

**Примечание**: В `tests/Pest.php` уже настроен глобальный `RefreshDatabase` для всех тестов, но явное добавление в тест повышает безопасность.

## 🔒 Гарантии безопасности

### Механизм защиты данных

1. **Изолированная база**: Все тесты выполняются на `agelto_testing`, а не на `agelto`
2. **RefreshDatabase**: Автоматически откатывает все изменения после каждого теста
3. **Проверено**: Рабочая база сохранила все данные:
    - Companies: 1 запись ✅
    - Users: 7998 записей ✅

### Как работает RefreshDatabase

```php
// До каждого теста
RefreshDatabase::beginDatabaseTransaction(); // Начинает транзакцию

// Тест выполняется
User::factory()->create(); // Создает тестовые данные

// После теста
RefreshDatabase::rollbackDatabaseTransaction(); // Откатывает все изменения
```

**Важно**: Даже если тест упадет с ошибкой, транзакция будет откатана и данные не сохранятся.

## ✅ Проверка безопасности

### Запуск тестов

```bash
cd /home/vostrikov/sibintek/projects/agelto
php artisan test --filter=TasksControllerTest
```

**Результат**:

- ✅ 17 тестов пройдено
- ✅ 88 проверок выполнено
- ✅ Рабочая база не затронута
- ⏱️ Время выполнения: 4.39s

### Проверка базы данных после тестов

```bash
# Проверить рабочую базу (должны быть данные)
php artisan tinker
>>> DB::table('users')->count()
=> 7998 ✅

# Проверить тестовую базу (должна быть пустая)
php artisan tinker --env=testing
>>> config('database.connections.mysql.database')
=> "agelto_testing"
```

## 📋 Правила безопасного тестирования

### ✅ ВСЕГДА делайте:

1. **Проверяйте конфигурацию** перед запуском тестов:

    ```bash
    cat phpunit.xml | grep DB_
    ```

    Должно быть:
    - `DB_CONNECTION=mysql`
    - `DB_DATABASE=agelto_testing`

2. **Используйте RefreshDatabase** во всех тестах:

    ```php
    uses(RefreshDatabase::class);
    ```

3. **Запускайте только необходимые тесты**:

    ```bash
    # Конкретный тест
    php artisan test --filter=TaskName

    # Конкретный файл
    php artisan test tests/Feature/TasksControllerTest.php
    ```

4. **Используйте фабрики** для создания данных:
    ```php
    User::factory()->create();      // ✅ Правильно
    User::create(['name' => '...']); // ❌ Избегайте
    ```

### ❌ НИКОГДА не делайте:

1. ❌ Не изменяйте `DB_CONNECTION` или `DB_DATABASE` в `phpunit.xml` без проверки
2. ❌ Не запускайте тесты без `RefreshDatabase`
3. ❌ Не используйте `--env=production` для тестов
4. ❌ Не удаляйте трейт `RefreshDatabase` из тестов

## 🚨 Что делать если данные были удалены

Если по какой-то причине данные рабочей базы были затронуты:

### 1. Немедленно остановите тесты

```bash
# Нажмите Ctrl+C чтобы остановить выполнение
```

### 2. Проверьте какая база используется

```bash
php artisan tinker
>>> config('database.connections.mysql.database')
```

### 3. Восстановите данные из резервной копии

```bash
# Если есть резервная копия
mysql -u root -p agelto < backup.sql

# Или запустите сидеры
php artisan db:seed
```

### 4. Исправьте конфигурацию

Проверьте `phpunit.xml` и убедитесь, что используется `agelto_testing`

## 🎯 Рекомендации на будущее

### 1. Создание новых тестов

При создании новых тестов всегда:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

uses(RefreshDatabase::class); // ← Всегда добавляйте это

test('example test', function () {
    // Используйте фабрики
    $user = User::factory()->create();

    // Выполняйте тесты
    expect($user)->toBeInstanceOf(User::class);
});
```

### 2. CI/CD Pipeline

Для автоматического тестирования добавьте проверку:

```yaml
- name: Setup test database
  run: |
      mysql -u root -e "CREATE DATABASE IF NOT EXISTS agelto_testing"
      php artisan migrate --database=mysql --env=testing

- name: Run tests
  run: php artisan test
```

### 3. Pre-commit Hook

Создайте `.git/hooks/pre-commit`:

```bash
#!/bin/bash
# Проверка конфигурации перед коммитом
if grep -q 'DB_DATABASE.*agelto"' phpunit.xml; then
    echo "❌ ОШИБКА: phpunit.xml использует рабочую базу!"
    exit 1
fi
echo "✅ Конфигурация тестов безопасна"
```

## 📊 Текущий статус

| Компонент             | Статус | Описание                        |
| --------------------- | ------ | ------------------------------- |
| phpunit.xml           | ✅     | Настроен для `agelto_testing` |
| RefreshDatabase       | ✅     | Активен глобально и локально    |
| Тестовая база         | ✅     | Существует и изолирована        |
| Рабочая база          | ✅     | Защищена и не затронута         |
| Тесты TasksController | ✅     | 17/17 пройдено                  |

## 🔍 Дополнительная информация

### Альтернатива: SQLite in-memory

Если в будущем установите расширение SQLite для PHP:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

**Преимущества:**

- ⚡ Быстрее выполнение тестов
- 🔒 Полная изоляция (база в памяти)
- 🧹 Автоматическая очистка после тестов

**Установка SQLite:**

```bash
sudo apt-get install php8.4-sqlite3
# или
sudo yum install php-sqlite3
```

## ✅ Заключение

**Все изменения внесены и проверены.**

Теперь безопасно:

- ✅ Писать новые тесты
- ✅ Запускать существующие тесты
- ✅ Использовать фабрики и сидеры в тестах
- ✅ Разрабатывать без риска потери данных

**Рабочая база `agelto` полностью защищена!**

---

_Дата: 2025-10-31_  
_Выполнено: Cursor AI_
