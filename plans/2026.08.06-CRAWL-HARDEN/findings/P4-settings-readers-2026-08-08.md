# P4 — инвентарь читателей legacy-настроек и троттлера — 2026-08-08

## Метод

Репозиторная проверка перед re-design P4.1:

```bash
rg -n -C 3 "crawler\.(http|throttle)" app tests config
rg -n -C 4 'PoliteThrottle|->wait\(' app tests
```

Это внутренняя фактура репозитория, не внешний источник.

## Результат

`crawler.http.*` читают три класса:

1. `app/Services/Procurement/Eis/CsvSourceMethod.php` — листинг и вкладки карточки.
2. `app/Services/Procurement/DocumentDownloader.php` — загрузка документов.
3. `app/Services/Procurement/Eis/Okpd2NodeSynchronizer.php` — служебная синхронизация дерева ОКПД2.

`PoliteThrottle` читает `crawler.throttle.*` и вызывается в пяти классах:

1. `CsvSourceMethod`.
2. `DocumentDownloader`.
3. `Okpd2NodeSynchronizer`.
4. `FreshRunProcessor`.
5. `ArchiveWindowScanner`.

Первые три вызова соответствуют обращениям к площадке; два последних добавляют паузу циклу
прогона после его работы. `Okpd2NodeSynchronizer` не получает `Crawler`, но сам находит
`Platform` через `requirePlatform()`.

## Вывод для плана

Старый validation P4.1 неосуществим: он одновременно требовал удалить `crawler.throttle.*` и
исключал `PoliteThrottle` из Scope. P4.1 переводит HTTP-настройки и оставляет единственный
переходный throttle-читатель; P4.4 — единственный владелец его полного поглощения общим budget.

`Okpd2NodeSynchronizer` не должен читать профильные overrides: для него используется
`CrawlStage::Listing` с `crawler: null`, то есть только слой площадки и дефолт конфига.

RAG:— (repo-grounded: `app/Services/Procurement/PoliteThrottle.php`,
`app/Services/Procurement/Eis/CsvSourceMethod.php`,
`app/Services/Procurement/DocumentDownloader.php`,
`app/Services/Procurement/Eis/Okpd2NodeSynchronizer.php`,
`app/Services/Procurement/Modes/FreshRunProcessor.php`,
`app/Services/Procurement/ArchiveWindowScanner.php`)
