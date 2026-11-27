# Agelto

Платформа автоматизированного мониторинга, подготовки документации, ИИ-скрининга и извлечения смет государственных и коммерческих закупок (44-ФЗ / 223-ФЗ).

## Поддерживаемый стек и runtime-версии

- **PHP:** `8.6` (`serversideup/php:8.6-fpm-nginx`, закреплён по digest; `pcntl`, `intl`, `bcmath`, `pdo_pgsql`, `pgsql`, `redis 6.3.0`, `gd`; в dev — `pcov` с закреплённого коммита upstream)
- **Framework & Admin UI:** Laravel 13, Filament 5, Livewire 4, Horizon 5, Pulse, AzGuard (`local/vendors/azguard`, закреплённый в `docker/private-vendors.lock.json`)
- **Качество:** Pest 5, PHPStan `max` через Larastan 3 без baseline, Pint
- **Frontend & Docs:** Node.js `26`, `pnpm` (`--frozen-lockfile --ignore-scripts`), Vite 7, Tailwind CSS 4, VitePress 1.6
- **Инфраструктура:** PostgreSQL `19` (`agelto/postgres:<IMAGE_TAG>`, `pg_partman`, `pg_stat_statements`; volume монтируется в `/var/lib/postgresql`), 3 изолированных контура Redis `8.12` (`redis` с `noeviction` и `AOF everysec` для очередей/Horizon/атомарных блокировок/heartbeat планировщика, `redis-cache` с `allkeys-lru` для кэша, `redis-session` с `noeviction` и `AOF everysec` для сессий), S3-совместимое хранилище Silo (`pgsty/silo`, поддерживаемый форк MinIO; сервис `minio`, runtime работает под ограниченной учётной записью `MINIO_APP_USER` / `minio_app_password`), изолированный Python-супервизор конвертации (`tool_supervisor.py` + `bwrap`: qpdf, Ghostscript, Poppler, Tesseract OCR)

Переход существующей установки с PHP 8.5, PostgreSQL 17 и MinIO описан в [обновлении окружения](docs/dev/runbooks/environment-upgrade.md), общая схема системы — в [обзоре архитектуры](docs/dev/architecture-overview.md).

## Доменная архитектура (`app/**`)

Код организован в плоской структуре `app/**` вокруг пяти доменов без платформо-зависимых ветвлений в общих DTO, моделях и сервисах:

1. **`Procurement` (`prc_*`)** — сквозной пайплайн закупок, нормализованные тендеры, лоты, документы, архивы и извлечённые сметные позиции.
2. **`Reference` (`ref_*`)** — справочники классификаторов (ОКПД2, КБК, КВР, регионы, единицы измерения, валюты).
3. **`Organization` (`org_*`)** — заказчики и организации; внешние идентификаторы передаются строго тройкой `(platformCode, type, value)`.
4. **`Llm` (`llm_*`)** — учёт запросов к провайдерам ИИ, резервирование бюджетов (`llm_budget_reservations`), идемпотентные квитанции (`ReceiptStore`) и гейт отправки (`AnalysisProviderSendGate`).
5. **`Platforms/<Code>`** — изолированные адаптеры конкретных площадок (например, `Platforms/Eis`).

## Process Core

Оркестрация обработки закупок построена на таблицах `prc_process_work`, `prc_process_attempts`, `prc_process_outbox`, `prc_operation_intents`, `prc_process_runs`, `prc_process_daemon_heartbeats` и доставляется единственным джобом `ExecuteWorkJob`:

- **8 стадий (`StageCode`) как направленный граф пайплайна:** `listing` → `detail` (и независимый периодический `status_refresh` по тендеру) → `download` → `prepare` → `screening` → `extraction` → `publication`. Активная стадия Process `prepare` принимает только PDF-документы (`PrepareHandler` → `PdfPreparationTools` / `PageBatchOcr` / `BoundedToolProcess` под `tool_supervisor.py`); отдельные стратегии (`LibreOfficeConverter`, `OcrConverter`, `ArchiveStrategy`, `SpreadsheetStrategy`, `GsfxStrategy`) существуют вне PDF-границы допуска Process Core.
- **4 фоновых демона (`process:daemon`):**
  - `relay` — публикация зафиксированных событий из `prc_process_outbox` (`work_wake`) в очереди Horizon;
  - `reconcile` — возврат просроченных leases (`prc_process_attempts`), карантин и сверка незавершённых исполнений (`RecoveryReconciler`);
  - `feed` — плановая подача регулярных работ (`listing`, `status_refresh`) и обработка `feeder_wake`;
  - `project` — проекция событий из `prc_process_outbox` (`projection`) и `prc_process_transitions` в витрины чтения.
- **Ключевые инварианты безопасности:**
  - Запрет любых сетевых HTTP-вызовов и объектного I/O (`S3`) внутри открытых транзакций БД (`OutboundExecutor::execute()`, `HttpTransport::sendOnce()`, `AnalysisProviderSendGate`, `PreparedProviderTransport::sendOnce()`, `ReceiptStore`).
  - Привязка исходящих соединений к проверенным публичным IP (`UrlPolicy` + `HttpTransport` DNS pinning) и строгий TLS-трест (`SourceTlsTrust`; `verify => false` запрещён).
  - Канонический порядок блокировок и сверка времени владения lease через `clock_timestamp()`.

## Среды выполнения: разработка, тестирование и эксплуатация

### 1. Локальная разработка (`docker-compose.yml`)

```bash
make vendor:sync
make bootstrap MODE=dev ENV_FILE=.env.example
make dev:up
make readiness:check
```

### 2. Изолированный тестовый стенд Process Core (`agelto_core_test`)

Проверка качества и функциональные тесты выполняются в изолированном контуре `agelto_core_test` (`docs/dev/runbooks/process-test-rig.md`), не затрагивающем рабочую БД разработки. Точечный запуск `make core-test-file` требует поднятого стенда (`make core-test-up`) и готовой изолированной схемы (`make core-test-reset`, сам `core-test-file` сброс схемы не выполняет):

```bash
make core-test-up
make core-test-reset
make core-quality
make core-test-unit
make core-test-install
make core-test-execution
make core-test-acquisition
make core-test-documents
make core-test-ai
make core-test-network
make core-test-observability
make core-test-platforms
make core-test-concurrency
make core-test-regression
make core-test-file TEST_FILE=tests/Feature/Health/ApplicationReadinessTest.php
```

Полный прогон для CI (без TIA):

```bash
make quality
make test:junit
make test:coverage
```

### 3. Production выкладка и восстановление (`docker-compose.prod.yml`)

Production использует исключительно заранее собранные неизменяемые образы `agelto/app:<IMAGE_TAG>`, `agelto/doctor:<IMAGE_TAG>` и `agelto/postgres:<IMAGE_TAG>` (без bind-mount исходного кода и `vendor` и без сборки приложения на хосте) и файловые секреты в `local/secrets/`:

```bash
# Предварительная проверка конфигурации и секретов без мутации Docker
make infra:preflight MODE=prod-runtime ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod

# Продвижение проверенной пары образов (проверяет совпадение org.opencontainers.image.revision и io.agelto.vendor-lock-sha)
make prod:image:promote ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod

# Выкладка и автоматическая проверка готовности (app:readiness --format=agent)
make prod:deploy:image ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod

# Отдельная проверка готовности production-контура
make prod:readiness ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod
```

## Опасные операции и границы безопасности

- **Запрещено** сбрасывать общую dev/prod базу (`migrate:fresh`, `db:wipe`, `docker compose down -v`) вне одноразового изолированного стенда.
- **Запрещено** использовать тег `latest` для production-выкладки (`IMAGE_TAG=latest` блокируется).
- **Запрещено** включать `APP_DEBUG=true` или хранить секреты в `.env` для production (проверяется `preflight.sh` и `10-secrets.sh` в режиме fail-closed).
- **Миграции в production** требуют явного гейта владельца: `make prod:migrate OWNER_GATE=approved ...`.
- **Восстановление из резервных копий (`restore-production.sh`)** разрешено только в изолированный target `*_rehearsal` после криптографической проверки `backup.manifest` и `manifest.sha256`.

## Каноническая документация и Runbooks

- **Архитектура и контракты:**
  - `.ai/guidelines/cheatsheet.md` — шпаргалка по стадиям, блокировкам, очередям, таблицам и CLI
  - `docs/dev/architecture.md` — доменная архитектура и границы слоёв
  - `docs/dev/process-core.md` — устройство Process Core и жизненный цикл работ
  - `docs/dev/database.md` — схема БД и секционирование (`pg_partman`)
  - `docs/dev/commands.md` — полный каталог команд `make` и скриптов
- **Эксплуатация (Production Runbooks):**
  - `docs/operators/production/_index.md` — оглавление эксплуатационной документации
  - `docs/operators/production/first-deploy.md` — первичное развёртывание
  - `docs/operators/production/releases.md` — регламент релизов
  - `docs/operators/production/backups.md` и `docs/operators/production/manual-backups.md` — резервное копирование и проверка целостности
  - `docs/operators/production/rollback-restore.md` — откат релизов и изолированное восстановление (`*_rehearsal`)
  - `docs/operators/production/secret-rotation.md` — ротация секретов
  - `docs/operators/production/troubleshooting.md` — диагностика сбоев

## Владельцы и сообщение об уязвимостях

Критичные контуры инфраструктуры, сетевой политики, Process Core и LLM защищены правилами `.github/CODEOWNERS` (`@axiomasoft/owners`, `@vostrikov`). О выявленных уязвимостях безопасности и инцидентах целостности данных сообщайте напрямую владельцам репозитория по закрытому каналу (без публикации эксплойтов и секретов в открытых issue/PR).
