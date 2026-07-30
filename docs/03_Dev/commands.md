---
audience: dev
type: reference
status: draft
---

# Команды — шпаргалка

Полная справка: `make help`. Все `make`-цели — тонкая обёртка над `docker compose exec`
(`docker/Makefile`), `ENV=prod` или алиасы `prod:*` переключают на `docker-compose.prod.yml`.

## make — общие

| Команда | Что делает |
|:--|:--|
| `make up` / `down` / `build` / `restart` / `ps` | Жизненный цикл стека |
| `make logs [SERVICE=app]` / `make logs:core` | Логи одного сервиса / app+horizon+scheduler разом |
| `make shell` | `bash` внутри `app` |
| `make artisan ARGS="..."` | Любая artisan-команда без ручного `docker compose exec` |
| `make composer ARGS="..."` | Composer внутри контейнера |
| `make db-migrate` / `db-fresh` / `db-seed` | Миграции/`migrate:fresh`/сиды |
| `make db:create-test` | Разовая настройка `agelto_test` |
| `make db:backup [ENV=prod]` | `pg_dump` + обязательная проверка restore на scratch-БД (P7.7) |
| `make test-preflight` / `test-php` | Проверка соединения с БД / полный прогон тестов |

## make prod:* — деплой (D26, канон живых очередей)

| Команда | Что делает |
|:--|:--|
| `make prod:deploy [COLD=1]` | Канон: pull → [`COLD=1`: `horizon:pause`] → `migrate --force` (только аддитивные!) → `horizon:terminate` (graceful) → `up -d` → [`COLD=1`: `horizon:continue`] → `horizon:status` |
| `make prod:deploy:code` | Быстрое обновление кода без миграций (`optimize:clear` + `config:cache` + рестарт) |
| `make prod:deploy:runtime` | Composer + миграции + config:cache + рестарт |
| `make prod:deploy:image` | Пересборка образа + полный `deploy:runtime` |
| `make prod:doctor` | Health-check: `/up`, наличие `vendor`, статус контейнеров |

Подробности деплоя — [`process/deploy.md`](process/deploy.md).

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

- Два `--env-file` нужны для dev по умолчанию (`.env` + опционально `.env.docker`) — `make`-цели уже
  это учитывают, вручную `docker compose` без `make` требует те же два флага.
- Подробнее по стеку/окружениям — [`docker/index.md`](docker/index.md).
