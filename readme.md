# Agelto

Платформа автоматизированного мониторинга, подготовки документации, ИИ-скрининга и извлечения смет государственных и коммерческих закупок (44-ФЗ / 223-ФЗ).

## Поддерживаемый стек и runtime-версии

- **PHP:** `8.5` (`serversideup/php:8.5-fpm-nginx`, `pcntl`, `intl`, `bcmath`, `pdo_pgsql`, `pgsql`, `redis 6.3.0`, `gd`)
- **Framework & Admin UI:** Laravel 12, Filament v4, Livewire v3, Horizon v5, Pulse, AzGuard (`local/vendors/azguard`, закреплённый в `docker/private-vendors.lock.json`)
- **Frontend & Docs:** Node.js `24`, `pnpm` (`--frozen-lockfile --ignore-scripts`), Vite 6, Tailwind CSS v4, VitePress
- **Инфраструктура:** PostgreSQL `17` (`pg_partman`, `pg_stat_statements`), Redis `7` (`ACL`, `AOF everysec`, `noeviction`), MinIO (`S3`), изолированный Python-супервизор конвертации (`tool_supervisor.py`: LibreOffice, qpdf, Ghostscript, Poppler, Tesseract OCR, 7zip/unar)

## Доменная архитектура (`app/**`)

Код организован в плоской структуре `app/**` вокруг пяти доменов без платформо-зависимых ветвлений в общих DTO, моделях и сервисах:

1. **`Procurement` (`prc_*`)** — сквозной пайплайн закупок, нормализованные тендеры, лоты, документы, архивы и извлечённые сметные позиции.
2. **`Reference` (`ref_*`)** — справочники классификаторов (ОКПД2, КБК, КВР, регионы, единицы измерения, валюты).
3. **`Organization` (`org_*`)** — заказчики и организации; внешние идентификаторы передаются строго тройкой `(platformCode, type, value)`.
4. **`Llm` (`llm_*`)** — учёт запросов к провайдерам ИИ, резервирование бюджетов (`llm_budget_reservations`), идемпотентные квитанции (`ReceiptStore`) и гейт отправки (`AnalysisProviderSendGate`).
5. **`Platforms/<Code>`** — изолированные адаптеры конкретных площадок (например, `Platforms/Eis`).

## Process Core

Оркестрация обработки закупок построена на таблицах `prc_work_items`, `prc_work_executions`, `prc_outbox_events`, `prc_execution_leases` и доставляется единственным джобом `ExecuteWorkJob`:

- **8 стадий (`Stage`):** `listing` → `detail` → `status_refresh` → `download` → `prepare` → `screening` → `extraction` → `publication`.
- **4 фоновых демона (`process:daemon`):**
  - `relay` — публикация зафиксированных событий из `prc_outbox_events` в очереди Horizon;
  - `reconcile` — возврат просроченных leases, карантин и сверка незавершённых исполнений;
  - `feed` — плановая подача регулярных работ (`listing`, `status_refresh`);
  - `project` — проекция результатов в витрины чтения.
- **Ключевые инварианты безопасности:**
  - Запрет любых сетевых HTTP-вызовов и объектного I/O (`S3`/MinIO) внутри открытых транзакций БД (`OutboundExecutor::sendOnce()`).
  - Привязка исходящих соединений к проверенным публичным IP (`UrlPolicy` + `HttpTransport` DNS pinning) и строгий TLS-трест (`SourceTlsTrust`; `verify => false` запрещён).
  - Канонический порядок блокировок и сверка времени владения lease через `clock_timestamp()`.

## Среды выполнения: разработка, тестирование и эксплуатация

### 1. Локальная разработка (`docker-compose.yml`)

```bash
make vendor:sync
make bootstrap MODE=dev ENV_FILE=.env.example
make dev:up
make readiness
```

### 2. Изолированный тестовый стенд Process Core (`agelto_core_test`)

Проверка качества и функциональные тесты выполняются в изолированном контуре `agelto_core_test` (`docs/dev/runbooks/process-test-rig.md`), не затрагивающем рабочую БД разработки:

```bash
make core-quality
make core-test-unit
make core-test-feature
make core-test-file TEST_FILE=tests/Feature/Health/ApplicationReadinessTest.php
```

Полный прогон для CI (без TIA):

```bash
make quality
make test:junit
make test:coverage
```

### 3. Production выкладка и восстановление (`docker-compose.prod.yml`)

Production использует исключительно заранее собранные неизменяемые образы `agelto/app:<IMAGE_TAG>` и `agelto/doctor:<IMAGE_TAG>` (без bind-mount исходного кода и `vendor` и без сборки приложения на хосте) и файловые секреты в `local/secrets/`:

```bash
# Предварительная проверка конфигурации и секретов без мутации Docker
make prod:preflight ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod

# Продвижение проверенной пары образов (проверяет совпадение org.opencontainers.image.revision и io.agelto.vendor-lock-sha)
make prod:image:promote ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod

# Выкладка и автоматическая проверка готовности (app:readiness --format=agent)
make prod:deploy:image ENV_FILE=.env.production IMAGE_TAG=<tag> COMPOSE_PROJECT_NAME=agelto_prod
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
