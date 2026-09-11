---
audience: dev
type: runbook
status: living
---

# Runbook: затор Horizon

## Symptoms

Растёт длина очередей `doc_prepare`/`ai_screening`/`ai_extraction`; `pipeline:stuck-check` алертит
`backlog_threshold` (`config('ai.pipeline.backlog_threshold')`, default 200 документов одновременно
в downloaded/prepared/screened) или `no_completions_minutes` (ни одного succeeded-анализа за окно
при непустом бэклоге).

## Triage

1. `make artisan ARGS="horizon:status"` / `make logs:core` (app+horizon+scheduler разом) —
   supervisor'ы живы и обрабатывают, или встали.
2. `make prod:doctor` (prod) — health `/up`, наличие `vendor`, статус контейнеров одним отчётом.
3. Проверить инвариант **job timeout < supervisor timeout < `queue.retry_after`** (D26,
   `config/horizon.php`/`config/queue.php`): самый длинный supervisor —
   `supervisor-ai-extraction=360s`; extraction checkpoint'ится на 120s и оставляет 150s на
   следующий provider call плюс 30s reserve, тогда как `redis.retry_after`=420s. Нарушение этого
   неравенства — job перезапускается очередью ДО того, как сам успел корректно завершиться (не
   применимо к D31-механике self-requeue — та завершается успешно ДО истечения таймаута по
   построению).
4. Redis durability (D34, только prod): `redis:7-alpine` без `appendonly yes` — crash/OOM теряет
   очередь с последнего RDB-снапшота (до часа работы пайплайна). Прод-конфиг уже несёт
   `--appendonly yes --appendfsync everysec --maxmemory-policy noeviction`
   (`docker-compose.prod.yml`) — если бэклог совпал с рестартом Redis, сверить, что контейнер
   поднялся именно с этой командой, не дефолтной.

## Fix

- Живые supervisor'ы, просто не успевают — временный масштаб (`config/horizon.php` `maxProcesses`)
  или дождаться (checkpoint-requeue не теряет прогресс, просто растягивает по времени).
- Мёртвый worker (упал контейнер, не увидел `SIGTERM`) — `make prod:workers-restart` (`horizon`+
  `scheduler`), затем `pipeline:retry-failed` для добравших `Failed` за время простоя.
- `stop_grace_period` меньше самого длинного job'а (Docker default 10s убивает 300+с job) — проверить
  `HORIZON_STOP_GRACE_PERIOD` (prod-канон 600s, `docker-compose.prod.yml`), НЕ снижать без пересчёта
  под реальный самый длинный supervisor-таймаут.

## Rollback

Деплой во время затора — следовать канону D26 (`process/deploy.md`): `horizon:pause` перед
рискованным деплоем (`COLD=1`), `horizon:terminate` (graceful, доделывает текущие jobs) НЕ `down`.

## Escalation

Затор не спадает после рестарта worker'ов И инвариант таймаутов не нарушен — вероятная причина вне
Horizon (провайдер LLM недоступен/квота — [`gemini-errors.md`](gemini-errors.md)); пороги
`backlog_threshold`/`no_completions_minutes` не откалиброваны на живом трафике (открытый вопрос
плана EST-AI) — систематические ложные алерты также эскалируются разработчику для калибровки.
