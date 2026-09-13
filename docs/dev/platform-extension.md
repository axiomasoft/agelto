---
audience: dev
type: howto
status: living
---

# Расширение площадки

Площадка в данных и crawl-capability — разные вещи. `Platform`/`Registry` можно завести для
справочника без кода; обход становится доступен только после exact definition в
`config/crawler.php` для `Platform.code`.

## Контур расширения

1. Создайте `Platform` и `Registry` в данных. Одинаковый `Registry.code` допустим у разных
   площадок, но пара `(platform_id, code)` уникальна.
2. Добавьте typed definition в `config/crawler.php` → `crawler.platforms`: supported source method
   и nested registry filter/form. `PlatformAdapterRegistry` — единственный reader этой карты.
3. Поместите source-specific adapter в `app/Services/Procurement/<Adapter>/`; descriptor живёт в
   `app/Dto/Procurement/Crawler/PlatformAdapterDefinition.php`, а Filament form — в exact schema
   namespace. Не добавляйте branch в factory, model, repository или generic Filament form.
4. Добавьте status mapping как platform default либо exact registry override. Exact registry имеет
   приоритет; неизвестный raw stage создаёт только exact-registry `unknown`.
5. Скопируйте synthetic matrix из `tests/Feature/Procurement/PlatformExtensionContractTest.php`:
   две площадки с одинаковым registry code должны резолвить разные filter/source-method classes,
   а missing platform, method или filter — завершаться explicit fail-closed exception.

Проверка одного candidate:

```bash
bash docker/tests/platform-extension-contract.sh
```

Carrier проверяет guarded `agelto_test`, migration rollback/reapply/refusal, config cache cycle,
focused matrix, scoped Pint/PHPStan, full `php artisan test --no-tia`, docs, плановые views,
цитаты и diff. Не используйте `|| true` или `--fix`: любой exit code остаётся красным.

Откат migration status mappings отказывается до изменения схемы, если существуют platform-scoped
rules. Диагностика missing mapping называет platform/registry/method, а отказ rollback говорит,
что данные platform applicability нельзя удалить без потери. Наблюдайте эти сообщения в тесте и
в операторском интерфейсе, а не подменяйте их fallback. Архитектурный test сканирует весь product
source и разрешает source literals только в exact adapter namespace, descriptor, config и seed
boundary; deliberate forbidden-core fixture обязан оставаться красным.
