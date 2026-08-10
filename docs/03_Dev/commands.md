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
| `make dev:up` / `dev:down` / `dev:ps` | Жизненный цикл dev-стека |
| `make dev:logs [SERVICE=app]` / `dev:shell` | Логи / `bash` внутри dev `app` |
| `make dev:artisan ARGS="..."` / `dev:composer ARGS="..."` | Artisan или Composer внутри dev `app` |
| `make agent:artisan ARGS="..."` | Non-TTY (`-T`) Artisan для агента |
| `make vendor:sync` | Клонирует точный private AzGuard commit из tracked manifest и проверяет Composer closure |
| `make vendor:check` | Без мутаций проверяет origin, полный SHA, чистоту package paths и Composer dry install |
| `make prod:config ENV_FILE=.env.production` | Только рендер production Compose, без запуска стека |
| `make image:build` | Собирает текущий PHP-image |
| `make prod:deploy:{code,php-deps,frontend,runtime}` | Pull + recreate готового immutable image для выбранного класса; требуются `IMAGE_TAG` и `COMPOSE_PROJECT_NAME` |
| `make prod:image:promote` | Pull готового immutable tag без build на production host |
| `make prod:deploy:image` | Promote готового tag и runtime recreate без build |

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
| `ai:cost-reconcile --from= --to= --file= [--fail-on-breach]` | Сверка нашего трекинга с ручной выгрузкой биллинга провайдера (CSV `date,cost[,currency,service,sku]`), runbook `docs/03_Dev/runbooks/billing-reconcile.md` |

## Docker: полезные комбинации

- `ENV_FILE` явно указывает единственный env-file (`.env` по умолчанию); preflight и `prod:config`
  передают его в Compose и никогда не запускают stack.
- Подробнее по стеку/окружениям — [`docker/index.md`](docker/index.md).
