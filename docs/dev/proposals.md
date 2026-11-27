---
audience: dev
type: explanation
status: living
---

# Предложения по развитию и открытые решения

Здесь собраны изменения, которые слишком велики или спорны, чтобы делать их попутно в ветке
`refactor/deep-polish`. По каждому пункту описано, что предлагается, почему, во что обойдётся и
что нужно решить владельцу. Ничего из перечисленного в ветке не реализовано, если не сказано
иначе.

## Окружение и зависимости

### Silo вместо MinIO: закрепить или искать альтернативу

Образы `minio/minio` и `minio/mc` пропали с Docker Hub, а `quay.io/minio` требует авторизации.
Ветка перешла на `pgsty/silo` — поддерживаемый форк MinIO community edition с тем же протоколом,
переменными `MINIO_*` и форматом данных.

- Плюсы: замена без изменений кода и конфигурации, есть `mc` (`mcli`) в том же образе.
- Риски: маленькое сообщество форка; Trivy ложно сообщает CVE-2026-39414 по пути Go-модуля.
  Исключение ограничено файлом `.github/trivy/silo.trivyignore` и истекает 2027-05-31.
- Альтернативы: Garage или SeaweedFS (другой API администрирования, нужна миграция объектов),
  управляемое S3 у хостера (меньше эксплуатации, но данные уходят с хоста).
- Решение владельца: оставить Silo и пересмотреть до 2027-05-31 или запланировать переход.

### pcov: вернуться на PECL

PECL-релиз pcov 1.0.12 не собирается на PHP 8.6, поэтому стадия `development` собирает pcov
из закреплённого коммита upstream. Когда выйдет релиз с исправлением, нужно вернуть
`pecl install pcov-<версия>` и удалить `ARG PCOV_COMMIT`.

### Vite 8 и VitePress 2

VitePress 1.6 требует Vite ≤ 7, поэтому каталог pnpm держит `vite: ^7.3.8`. После стабильного
VitePress 2 можно перейти на Vite 8 одним коммитом для фронтенда и документации.

### Mermaid в собранной документации

Схемы в документации пишутся в mermaid. VitePress 1.6 без плагина показывает их как блоки кода.
Сами схемы при этом рендерятся в GitHub и IDE. Плагин `vitepress-plugin-mermaid` рисует их в
браузере, но mermaid использует inline-стили и `eval`-подобную генерацию SVG. Это противоречит
строгому CSP приватной документации (`verify-dist`, отклонение активных SVG). Варианты:
рендерить SVG на этапе сборки (mermaid-cli в стадии сборки документации, проверка SVG тем же
верификатором) или оставить как есть.

### Valkey вместо Redis

Redis 8 распространяется под AGPLv3/RSALv2/SSPLv1. Для закрытого self-hosted проекта это
допустимо, но Valkey 9 (BSD) совместим по протоколу и с Horizon. Переход — смена образа и
повторный прогон faults-набора с `FLUSHALL`. Решение владельца: юридическая политика лицензий.

### Ubuntu 26.04 как целевой хост

Runbooks и `bootstrap` поддерживают Ubuntu 24.04. Ubuntu 26.04 LTS вышла в апреле 2026.
Предлагается добавить её в матрицу host-контракта, оставив 24.04 до конца её поддержки.

### mpdf и исключение `php+`

mpdf/mpdf 8.3.x ограничивает PHP версией `~8.5.0`; в ветке разработки upstream уже `~8.6.0`.
Поэтому образ задаёт `COMPOSER_IGNORE_PLATFORM_REQ=php+`: игнорируется только верхняя граница
PHP, нижние границы и требования к расширениям проверяются. Когда выйдет тег mpdf с поддержкой
PHP 8.6, исключение нужно удалить. Альтернатива — проверить, нужен ли mpdf вообще: генерация PDF
может перейти на HTML → PDF в изолированном супервизоре инструментов.

## Качество кода

### Строгие флаги PHPStan для offset-доступа

`reportPossiblyNonexistentGeneralArrayOffset` и `reportPossiblyNonexistentConstantArrayOffset`
дают около 420 замечаний. В основном это чтение конфигурации и JSON-структур. Предлагается
включать флаги по каталогам, начиная с `app/Services/Procurement/Process`, и заменять чтение
массивов value-объектами (`TypedConfig`, DTO), а не подавлять замечания.

### checkUninitializedProperties

Флаг выключен намеренно: Laravel-модели, Livewire-компоненты и `ExecuteWorkJob` заполняют
свойства вне конструктора. Включать его стоит вместе с правилом-исключением для этих базовых
классов. Для `ExecuteWorkJob` нужно отдельно решить, где задаётся `timeout`: сейчас он
согласован с таблицей таймаутов стадий, и автоматическое изменение опасно.

### Дублирование Pint и PHPStan в CI

`preflight` в CI и `core-quality` на стенде запускают Pint и PHPStan повторно. Можно оставить
их только в `preflight`, а `core-quality` сократить до архитектурных и schema-проверок. Это
экономит около 4–5 минут на прогон, но `core-quality` перестаёт быть самодостаточной локальной
командой.

### Версионирование ключей plan-item в данных

Inventory схемы, манифесты fixtures, миграции и поля отчётов содержат ключи с ID этапов плана
(`P2.6`, `D49` и т. п.). Из комментариев и сообщений кода они удалены, но в данных остались:
это ключи сравнения, и их переименование ломает проверку повторного использования
доказательств. Предлагается ввести нейтральные стабильные ключи с картой «старый ключ → новый»
и одной миграцией сборщика отчётов.

## Документация

### Язык AGENTS.md, CLAUDE.md и .ai/guidelines

Это конфигурация агентов, а не пользовательская документация, и контрактные скрипты ищут в них
английские фразы. Предлагается оставить их на английском. `LICENSE.md` тоже остаётся на
английском.

### process-core.md, process-schema.md и subagents-and-workflows.md

В `process-core.md` около 70 англоязычных абзацев. Это исторический журнал реализации по
этапам плана с их ID. Построчный перевод сохранит устаревшую структуру «по этапам». Лучше
переписать страницу как текущий русский справочник по подсистемам (ledger, допуск, lease,
сеть, документы, ИИ) со ссылками на тесты, а историю этапов перенести в архив. Это отдельная
задача на 1–2 дня. Генерируемый блок inventory в `process-schema.md` рендерится кодом
(`SchemaInventory`) и проверяется верификатором; переводить его заголовки нужно в генераторе и
верификаторе одновременно. `docs/dev/subagents-and-workflows.md` сгенерирован harness
(`DO NOT edit`); его русская версия уже есть в `ai-context/subagents-and-workflows.md`, поэтому
английский дубликат стоит удалить или перегенерировать на русском.

## CI и процесс

### Запуск CI для веток

Workflow запускается на pull request, push в `main`, по расписанию, на релизе и вручную. Push в
рабочую ветку CI не запускает. Для проверки ветки без PR нужен `workflow_dispatch` с выбором
ветки. Сейчас все прогоны в организации падают за 2 секунды из-за биллинга GitHub Actions
(«recent account payments have failed or your spending limit needs to be increased»). Пока
биллинг не восстановлен, CI на ветке недоступен.

## Стабильность тестов

### Падения, которые уже есть в `main`

На стенде (PHP 8.6, PostgreSQL 19, Redis 8.12) полный прогон четырёх шардов даёт 64 падения в
31 файле. Те же файлы прогнаны на коде и `composer.lock` из `main` (`c8bd595d`, Filament 5.8.4,
Guzzle 7) в том же окружении: падают те же тесты с теми же сообщениями. Значит, это не регрессия
ветки и не следствие обновления Filament или Guzzle. Большая часть появилась после интеграции
Proxyma: код изменился, тесты не обновили.

| Группа | Файлы | Причина |
| --- | --- | --- |
| Хранилище и источники | `StorageAddressingGateTest`, `CsvSourceMethodTest`, `AuditRemediationTest`, `CardCollectDetailsTest`, `CrawlRunJobTest`, `EisListingReliabilityTest`, `BudgetExhaustionTest`, `ArchiveWindowScannerTest` | класс `BoundedResponseReader` удалён, `fetch` теперь возвращает `FetchItemRejected`, `LocalEstimateSourcePreparer` читает конфигурацию диска, сигнатура `DownloadFixtures::handler()` изменилась |
| Proxyma и сеть | `ProviderJointSendTest`, `ProxymaUiAuthorityTest`, `SupplementalDaemonWiringTest`, `BlockedProviderRecoveryTest`, `RecoveryReconcileConcurrencyTest`, `MultiProcessPipelineE2eTest`, `DaemonRedisLossAcceptanceTest`, `HorizonPipelineTest`, `LoadTest` | SSRF-запрет приватных прокси (`Proxy address denied`), новый секрет `proxyma_wire_digest_key`, `TransportBlocked configuration_hold`, `reconcile_category_failed` |
| Панель | `AdminRouteInventoryTest`, `NavigationLabelsTest`, `TrainingUiIndependenceTest`, `LlmRouteResourceTest`, `PlatformUiRegistryTest` | добавлены страницы Proxyma (66 маршрутов вместо 61), меню и бюджет запросов не обновлены |
| Готовность и консоль | `ApplicationReadinessTest` (20), `ReleaseReadinessDiagnosticsTest`, `CreateInitialAdminTest`, `FreshCrawlerScheduleTest`, `VerifyFreshBoundaryTest`, `RefreshCyclesTest` | проверки готовности учитывают Proxyma, `remainingBudgetMs` отсутствует и в `main` |
| Прочее | `AcceptanceManifestTest`, `ProjectionRetentionTest`, `SourceControlPolicyTest` | манифест сценариев и политика источников не догнали код |

Ветка исправила такие тесты там, где они мешали прогону: зависание
`StatusMappingApplicabilityMigrationTest` (шард не завершался), `FilamentSchemaContractTest`, а
также проверки роли SuperAdmin в `admin:create-initial` и в readiness `release_state`. AzGuard
запрещает проверки внутри открытой транзакции, поэтому обе проверки падали всегда.

### Clean-install e2e (Playwright)

В `main` clean-install не доходил до браузера: останавливался на создании администратора. В ветке
установка, readiness и 8 из 12 сценариев Playwright проходят. Ещё 4 сценария ожидают то, чего в
`main` уже нет:

| Сценарий | Ожидание | Что в `main` |
| --- | --- | --- |
| `private-documentation.spec.ts` | `.mermaid svg` на странице deployment | Mermaid-бандл убран коммитом `bf732604`, схемы показываются как код |
| `admin-navigation-responsive.spec.ts`, super-admin и operator | страница `/admin/procurement/crawler/proxies/create` и текст «Подключение прокси» | ресурс прокси удалён при интеграции Proxyma (`365a93cd`) |
| `admin-navigation-responsive.spec.ts`, operator, прямой URL | `/admin/roles` отвечает 403 | маршрут отвечает 404 |

Нужно решение владельца: вернуть детерминированный рендер Mermaid (например, SVG на этапе сборки)
или убрать это ожидание; перевести сценарий оператора на страницы Proxyma; решить, должен ли
запрещённый маршрут отвечать 403 или 404.
Остальные исправления меняют ожидания сценариев, закреплённых в манифесте process-core. Решать,
какое поведение правильное, должен владелец Proxyma-интеграции. Предлагается отдельная ветка на
каждую группу таблицы, начиная с «Хранилище и источники»: там удалённые классы, а не спорное
поведение.

### Мажорные версии Guzzle 8, PSR-7 3 и Filament 5.12

`build(deps)` поднял мажоры Guzzle, `guzzlehttp/psr7`, `guzzlehttp/promises` и `brick/math`, а
также Filament до 5.12. Сравнение выше не нашло ни одного теста, который падает только из-за них.
Рекомендация: оставить. Если владелец хочет минимальный риск перед релизом, можно закрепить
`guzzlehttp/guzzle:^7.15` и `filament/filament:5.8.*` одним коммитом. Laravel 13 допускает обе
ветки Guzzle.
