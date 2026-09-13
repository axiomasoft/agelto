---
audience: dev
type: reference
status: living
---

# Команды — шпаргалка

Полная справка: `make help`. Root `Makefile` только делегирует в `docker/Makefile`; канонические
цели namespaced и не переключают окружение скрытой переменной.

## make — канон P1

| Команда | Что делает |
|:--|:--|
| `make infra:preflight MODE=dev ENV_FILE=.env.example` | Fail-closed проверяет host/daemon, vendor, non-secret env, file secrets, ports и тихий Compose render без запуска, build или pull |
| `make bootstrap MODE=dev\|prod-build ENV_FILE=<file> [IMAGE_TAG=<immutable-tag>] [ADMIN_PASSWORD_FILE=<path>]` | Resumable vendor → secrets → preflight → image/dependencies → dev migrate/base seed/health or prod image-contract carrier; optional initial admin reads a password file only |
| `make dev:seed-demo` | Заполняет local/testing demo-аккаунты без перезаписи существующих пользователей; требует `DEV_SEED_PASSWORD` |
| `make quality` | Детерминированный P10 quality gate: locked Composer audit, PHPStan max без baseline, Pint, Rector dry-run, P10 remediation/security carrier и полный PHP suite. |
| `make test:edit` | Локальный edit-loop с Pest TIA; replay ускоряет только разработку и не является evidence приёмки. |
| `make test:full` / `test:junit` / `test:profile` / `test:coverage` | Полный `--ci --no-tia` suite; JUnit, profile и Clover сохраняют CI evidence без TIA replay. |
| `make test:tia-refresh` / `test:tia-reset` | Явно пересобирает или удаляет только project-scoped TIA graph; baseline workflow появится в P11.5. |
| `make test:shard SHARD=1/2` | Один full no-TIA time-balanced shard для измерений; пока P11.3 не докажет изоляцию, release default остаётся serial и shards не являются acceptance evidence. |
| `make test:parallel PROCESSES=2\|4` | Guarded Pest parallel proof: Laravel worker обязан получить exact token DB, а все app-owned disks — свой local process root. Не заменяет full serial acceptance. |
| `make dev:admin-create PASSWORD_FILE=<path>` / `make dev:admin-reset PASSWORD_FILE=<path>` | Явное создание initial SuperAdmin или reset; пароль монтируется из файла и не передаётся в argv |
| `make doctor:local` | Read-only Laravel Doctor в agent-формате для локальной среды |
| `make readiness:check` | Active DB/cache/storage/release/assets/Horizon/scheduler readiness; `/up` не заменяет эту проверку |
| `make assets:check` / `assets:sync` / `assets:repair` | Проверка и canonical Filament asset carrier; repair не меняет image, secrets, DB или volumes |
| `make prod:doctor IMAGE_TAG=<immutable-tag>` | One-off Doctor image; agent output, `--fail-on=warn`, без `--fix` |
| `make dev:up` / `dev:down` / `dev:ps` | Жизненный цикл dev-стека |
| `make dev:health` | Проверяет `/up` внутри running dev `app`; bootstrap retries this canonical health target |
| `make dev:logs [SERVICE=app]` / `dev:shell` | Логи / `bash` внутри dev `app` |
| `make dev:artisan ARGS="..."` / `dev:composer ARGS="..."` | Artisan или Composer внутри dev `app` |
| `make agent:artisan ARGS="..."` | Non-TTY (`-T`) Artisan для агента |
| `make vendor:sync` | Клонирует точный публичный AzGuard commit из tracked manifest и проверяет Composer closure |
| `make vendor:check` | Без мутаций проверяет origin, полный SHA, чистоту package paths и Composer dry install |
| `make prod:config ENV_FILE=.env.production` | Только рендер production Compose, без запуска стека |
| `make image:build IMAGE_TAG=<immutable-tag>` | Собирает same-SHA `app` + `doctor` image pair; `latest` запрещён |
| `make documentation:check` | Собирает VitePress 1.6.4 дважды, проверяет manifest/hostile fixtures и immutable private tree в application image |
| `bash docker/tests/ci-contract.sh` | Проверяет SHA-pinned GitHub Actions, least-privilege triggers, restore-only PR caches, local carriers, secret fail-closed boundary и D42 coverage. |
| `make prod:deploy:{code,php-deps,frontend,runtime}` | Pull + recreate готового immutable image для выбранного класса; требуются `IMAGE_TAG` и `COMPOSE_PROJECT_NAME` |
| `make prod:image:promote` | Pull готового immutable tag без build на production host |
| `make prod:deploy:image` | Promote готового tag и runtime recreate без build |
| `bash docker/tests/clean-install-e2e.sh` | Isolated tracked-tree bootstrap и Chromium login/admin smoke; требует `E2E_ADMIN_EMAIL` и password-file вне argv |

## artisan — пайплайн документов/смет

| Команда | Назначение |
|:--|:--|
| `pipeline:status` | Срез по статусам документов/анализов |
| `pipeline:stuck-check` | Поиск зависших (пороги не откалиброваны — открытый вопрос плана) |
| `pipeline:retry-failed [--limit=]` | Ретрай `Failed` по backoff-расписанию |
| `procurement:convert-xlsx-to-pdf {input} {output}` | Ручной прогон `SpreadsheetStrategy`-конвертера |

## artisan — краулинг

| Команда | Назначение |
|:--|:--|
| `crawler:run-fresh {crawler?}` | Обход свежих извещений по профилю |
| `crawler:run-archive {crawler} {--from=} {--to=}` | Обход архива за диапазон дат |
| `crawler:fetch-details {procurement?}` | Дозагрузка деталей закупки |
| `crawler:rescan-backlog` | Пересканирование бэклога |

## artisan — LLM/AI

| Команда | Назначение |
|:--|:--|
| `ai:prompts:sync [--check]` | Синхронизация обоих корней канона промтов → `llm_prompts` (`--check` — только сверить дрейф) |
| `ai:cost-report [--days=7]` | Отчёт расхода по провайдеру/модели/назначению + разрез по календарным дням и подпись источника (`наш трекинг (llm_calls)`, лаг, горизонт retention) |
| `ai:caches {list\|prune} [--hits] [--window=24]` | Explicit context cache: список/prune, доля implicit-хитов |
| `estimate:eval:run [--task=extraction] [--dataset-version=] [--limit=] [--dry-run]` | Прогон по golden-датасету, метрики precision/recall/F1 |
| `estimate:dataset:import {path} [--task=] [--source=manual] [--gold-version=1]` | Импорт папки эталонов в `prc_ai_dataset_examples` |
| `estimate:dataset:export [--format=sft\|dpo] [--task=extraction] [--disk=] [--path=]` | Экспорт gold-датасета в JSONL |
| `ai:storage:lifecycle` | MinIO ILM-обслуживание блобов (P7.7) |
| `ai:cost-reconcile --from= --to= --file= [--fail-on-breach]` | Сверка нашего трекинга с ручной выгрузкой биллинга провайдера (CSV `date,cost[,currency,service,sku]`), runbook `docs/dev/runbooks/billing-reconcile.md` |

## Docker: полезные комбинации

- `ENV_FILE` явно указывает единственный env-file (`.env` по умолчанию); preflight и `prod:config`
  передают его в Compose и никогда не запускают stack.
- Подробнее по стеку/окружениям — [`docker/index.md`](docker/index.md).

## Канонический inventory

Полный source-derived Make/script inventory с audience, environment, mutation, inputs, exit/resume
и evidence contract генерируется в
[`P12-command-matrix.md`](https://github.com/axiomasoft/agelto/blob/main/plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md).
Этот документ объясняет отдельные команды, но не определяет второй command contract.
