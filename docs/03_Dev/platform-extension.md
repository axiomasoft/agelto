---
audience: dev
type: how-to
status: living
---

# Расширение площадки

Площадка в данных и crawl-capability — разные вещи. `Platform`/`Registry` можно завести для
справочника без кода; обход становится доступен только после exact definition в
`config/crawler.php` для `Platform.code`.

1. Создайте platform и registry в данных. Одинаковый `Registry.code` допустим у разных платформ.
2. Добавьте definition в `crawler.platforms`: supported source method и nested registry filter/form.
3. Добавьте status mapping: platform default или exact registry override. Exact registry имеет приоритет.
4. Скопируйте synthetic matrix из `PlatformExtensionContractTest.php` и запустите `bash docker/tests/platform-extension-contract.sh`.

Отсутствующая definition, method или filter — fail-closed; global EIS fallback запрещён. Откат
migration status mappings отказывается до изменения схемы, если существуют platform-scoped rules.
Новый adapter остаётся в `Services/Procurement/<Adapter>/`; не добавляйте platform branch в
factories, models, repositories или Filament.
