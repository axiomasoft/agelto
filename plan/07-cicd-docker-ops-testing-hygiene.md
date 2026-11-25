# План 07: CI/CD, Docker, Production Operations, Тестирование и Гигиена репозитория

**Волна внедрения:** W0 (восстановление CI, GSFX-фикстура, удаление `estimates/files.zip`), W5 (production-скрипты, off-host бэкапы, Nginx/Dockerfile hardening, оптимизация тестового контура и документации)  
**Затрагиваемые слои:** `.github/workflows/**`, `docker/**`, `docker-compose*.yml`, `tests/**`, `.claude/**`, `.ai/**`, `docs/**`.

---

## 1. CI/CD и унификация с `agelto_core_test` (W0)

### [CRITICAL] C1–C4 & [HIGH] H1–H2. Починка CI и перевод на изолированный `agelto_core_test`
- **Файлы:**
  - `.github/workflows/ci.yml:22-381`, `.github/workflows/tia-baseline.yml:22-39`
  - `docker/tests/clean-install-e2e.sh:98-113, 160, 166`
  - `docker/scripts/collect-release-quality-evidence.sh:13, 32-48`
  - `docker/compose.core-test.yml:69`, `tests/Fixtures/ProcessCore/runtime-inputs.json:159-164`
- **Выявленные дефекты:**
  1. **`quality` job сломан:** поднимает dev-стек (`COMPOSE_PROJECT_NAME=agelto_ci_*`) и вызывает `make quality` → `rig.py core-quality`, который требует запущенный `agelto_core_test` → гарантированный отказ.
  2. **`clean-install-e2e.sh` падает на v2 `private-vendors.lock.json`:** читает `['commit']` вместо `repositories[]`, не клонирует `proxyma`, а на строке 166 настраивает MinIO от имени ещё не созданного `agelto_app` вместо `MINIO_ROOT_USER`.
  3. **`tests`/`coverage`/`release-quality`/`tia-baseline.yml` падают на чистом runner:** не передают `ENV_FILE=.env.example` (падают с exit 64), не мигрируют `agelto_test` (exit 68), а `tia-baseline.yml` не чекаутит `proxyma`.
  4. **Негерметичная GSFX-фикстура:** `estimates/files/Администрация культуры и спорта 3.gsfx` (6.2 MB) в `.gitignore`, но жёстко монтируется в `compose.core-test.yml:69` и проверяется по SHA-256 в `rig.py` — на свежем клоне/CI запуск невозможен.
  5. **15× последовательный прогон PHP-тестов на каждый PR:** `tests` (1×) + `coverage` (1×) + `release-quality` (13× последовательно при `timeout-minutes: 35`) — гарантированный таймаут.
- **План исправления:**
  1. Вынести чекаут приватных вендоров (`azguard` + `proxyma` по `vendor_manifest.py --rows`) в общий composite action `.github/actions/setup-vendors` и использовать в `ci.yml`, `tia-baseline.yml` и `clean-install-e2e.sh`.
  2. Починить MinIO bootstrap в `clean-install-e2e.sh` через `make prod:storage:init`.
  3. Обеспечить герметичную GSFX-фикстуру: либо закоммитить минимальный синтетический `.gsfx` в `tests/Fixtures/ProcessCore/`, либо добавить детерминированный fetch с проверкой SHA-256 перед `core-test-up`.
  4. Перевести `quality` и PHP-тесты CI на `agelto_core_test` (`core-test-up` → `core-test-env` → `core-quality` + `DocsIntegrityTest` + `composer audit` → шардированные тесты `make test:shard` / `make test:parallel` в 4 потока, цель PR CI < 8 мин).
  5. Вынести 13-прогонный `release-quality`, `core-test-load` и `core-test-faults` на `schedule` / `workflow_dispatch` / release-теги (при этом `core-test-faults` включить в `main`).
  6. Обновить `aquasecurity/trivy-action` с устаревшей `v0.9.2` до актуального SHA-pin, добавить сканирование `node:24-bookworm` и `boky/postfix:5.1.0`, поэтапно поднять `critical_file_floor` с 55% до 80% в `tests/performance-budget.json`.
- **Верификация:** Полный зелёный прогон CI на чистом runner / в `/tmp/fresh-clone`.

---

## 2. Docker, Nginx и Production Operations (W5)

### [CRITICAL] C5. Production-скрипты-заглушки и локальные бэкапы
- **Файлы:**
  - `docker/scripts/{backup,restore,release,rollback,first-production-deploy,rotate-production-secrets,backup-alert}*.sh`
  - `docker/host/systemd/agelto-backup*.service`
  - `docker/host/backup/backup-local-enabled.sh:49, 62, 76, 87`
- **Проблема:**
  - Все 7 production-скриптов отклоняют `apply` заглушкой (`apply requires the follow-up operator rehearsal authority`).
  - Systemd-юниты ссылаются на `/usr/local/bin/agelto-backup*`, которые не устанавливаются `host-provision.sh`.
  - Единственный рабочий `backup-local-enabled.sh` работает только локально (`offhost=0`), запускает `app` как `--user root` и шифрует секреты неаутентифицированным `openssl enc -aes-256-cbc -pbkdf2` без `-iter`.
- **План исправления:**
  1. Реализовать реальные `apply`-пути (или заменить заглушки на боевые скрипты) с off-host репликацией (`rclone`/`restic`) и аутентифицированным шифрованием (`age`/`restic`).
  2. Перевести `backup-objects.php` на `--user www-data`.
  3. Устанавливать systemd-обёртки в `host-provision.sh` и проверять через `systemd-analyze verify` + staging restore drill.

---

### [HIGH] H3. Рассогласование Host и Container Nginx и Hardening
- **Файлы:** `docker/host/nginx/agelto.conf:20-45`, `docker/nginx/default.conf:7-8, 17-37, 43, 50-51, 58-62`
- **Проблема:**
  - `client_max_body_size`: `32m` на хосте vs `200M` в контейнере (загрузки 32–200 MB падают с 413).
  - `proxy_read_timeout`: `60s` на хосте vs `fastcgi_read_timeout 300` в контейнере.
  - Дублирующиеся противоречивые `X-Frame-Options` (`DENY` vs `SAMEORIGIN`).
  - `add_header` внутри `location` в `default.conf` сбрасывает server-level `X-Content-Type-Options: nosniff` для `/documentation`, `/admin/documentation` и статики.
  - В `agelto.conf` нет `server_tokens off`, HSTS, `ssl_protocols TLSv1.2 TLSv1.3`, `Permissions-Policy`.
- **План исправления:** Выровнять лимиты (`200M`) и таймауты (`300s`), добавить `proxy_hide_header X-Frame-Options`, продублировать `X-Content-Type-Options "nosniff" always` во всех `location` с `add_header`, добавить HSTS/`server_tokens off`/TLSv1.2+ и проверки `curl -sI` в `docker/tests/ingress-backup-contract.sh`.

---

### [HIGH] H4. Dockerfile: non-root, пиннинг по `@sha256:` и разделение тяжёлого образа
- **Файлы:** `docker/php/Dockerfile:3-33, 143, 168`, `docker-compose.yml:320-369`, `docker-compose.prod.yml:146, 271-411`, `docker/compose.core-test.yml:172-199`
- **Проблема:**
  - Плавающие теги базовых образов (`serversideup/php:8.5-fpm-nginx`, `node:24-bookworm`, `postgres:17`, `redis:7-alpine`, `minio/minio:latest`).
  - `application` и `doctor` заканчиваются на `USER root`.
  - `runtime-base` ставит `libreoffice-*`, `tesseract-ocr*`, `ocrmypdf`, `ghostscript`, `poppler-utils`, `qpdf`, `7zip`, `unar` во все 7 контейнеров (>1 GB лишнего веса и CVE-поверхности в `app`, `scheduler` и 4 демонах).
  - Сервисы данных в `docker-compose.prod.yml` не имеют `security_opt: [no-new-privileges:true]` и `stop_grace_period`; у `horizon` висит неиспользуемый `shm_size: '2gb'`, в dev висит пустой `browser` контейнер.
- **План исправления:** Запинить все базовые образы по `@sha256:`; разделить образ на лёгкий `app-base` (web, scheduler, 4 демона) и `worker-prepare` (с LibreOffice/OCR/архиваторами); добавить `no-new-privileges:true` и `stop_grace_period: 30s` на data-сервисы.

---

### [MEDIUM] M2. `release-deploy.sh` и `vendor_manifest.py`
- **Файлы:** `docker/scripts/release-deploy.sh:40-44, 70-73`, `docker/scripts/vendor_manifest.py:53`
- **Исправление:** Добавить `--wait` в `compose up -d` и поддержку `IMAGE_REGISTRY` в `release-deploy.sh`; заменить жёсткую проверку `"0.1.0"` в `vendor_manifest.py:53` на валидацию semver / `dev-main`.

---

## 3. Качество тестового набора и ворота

- **[HIGH] H5 (Снижение накладных расходов `manifest.json`):** `tests/Fixtures/ProcessCore/manifest.json` (748 KB, 784 SHA-256 пина, включая 108 классов `app/` и 519 тестов) тормозит любой рефакторинг. Ограничить SHA-256 пиннинг иммутабельными внешними/контрактными фикстурами (`contract_fixtures`, `estimates/gold`), а для остального положиться на Git и архитектурные тесты; добавить `make core-manifest-sync`.
- **[MEDIUM] M3 (Поведенческие Filament-тесты вместо regex):** заменить хрупкий парсинг исходников в `FilamentSchemaContractTest.php` и `FilamentTableContractTest.php` на инстанцирование реальных `Schema`/`Table` / `Livewire::test(...)` и проверку объектов колонок/компонентов.
- **[MEDIUM] M4 (Playwright E2E):** выровнять версию образа в `docker/compose.core-test.yml:217` (`v1.56.1` → `1.62.1` как в `package.json`); добавить 2–3 ключевых операторских E2E-сценария (поиск закупки → карточка → документ/смета; pause/resume скоупа).
- **[MEDIUM] M5 (Rector):** либо подключить `rector process --dry-run` в `core-quality`, либо удалить неиспользуемые `rector/rector` и `driftingly/rector-laravel` из `composer.json`.

---

## 4. Гигиена репозитория и AI-контекста

- **[HIGH] H6 (Противоречивое правило `.claude/rules/tests.md:17,23`):** предписывает `docker compose exec app php artisan test` (противоречит `AGENTS.md` / `agelto_core_test`) и содержит утечку чужого проекта `(on chatom >60% hits)`. Перекрыть через `.claude/rules/agelto-reviewer-overrides.md` и обновить upstream-шаблон.
- **[MEDIUM] M6 (Мусорный архив и дубль папок):** удалить/вынести `estimates/files.zip` (196 MB, untracked) и добавить `/estimates/*.zip` в `.gitignore`; консолидировать `Audits/` (ignored) и `audits/` (tracked) в одну каноническую папку.
- **[MEDIUM] M7 (Устаревшие workflow):** удалить или архивировать `.claude/workflows/estai-milestone-review.js` и `estai-phase-runner.js` (ссылаются на архивный `EST-AI`).
- **[MEDIUM] M8 (Дрейф документации):** синхронизировать `docs/dev/process/deploy.md` (`HORIZON_STOP_GRACE_PERIOD` 370s, 7 сервисов вместо 3), `docs/dev/process/testing.md` (актуальный `make quality` / `agelto_core_test`, убрать `docker exec agelto_app` и `make artisan`), `docs/operators/production/commands.md` (добавить 4 `process-*` демона) и проверить `DocsIntegrityTest`.
- **Незакоммиченный diff в working tree:** отредактированные `docker/private-vendors.lock.json`, `docker/scripts/deploy-repositories.sh`, `docker/scripts/vendor_manifest.py` (переход `azguard-private` → `azguard`) и `.grok/hooks/lifecycle.py` — оформить отдельным чистым коммитом после проверки `PrivatePackagesArePinned` и `clean-install-e2e.sh`.
