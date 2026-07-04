# HANDOFF — 2026-07-04 — after P0.2

**Next:** /task:plan-exec V2-CORE P0.3
**Done:** P0.1 (Laravel 12 скелет) + P0.2 (полный Docker-стек: app/horizon/scheduler/postgres/redis/minio/browser, все healthy, `/up` 200). laravel/horizon добавлен (нужен для контейнера horizon). Старый v1 Docker-стек на этой машине остановлен (`docker compose down`, без `-v` — данные в volume'ах целы).
**Remaining:** P0.3 (test-gate/test-isolation-guard/healthcheck-тест) — вся Фаза 1 (P1.1-P1.11).
**Sources of truth:** `plans/V2-CORE/plan.md` · `plans/V2-CORE/phases/P0.md` (Known Deviations в P0.2 — важно прочитать перед P0.3/P1) · `docker-compose.yml`
**Open risks:** —
**Workarounds/Deferred/Open questions:**
- workarounds: `name: agelto_v2` в compose (иначе коллизия volume-неймспейса со старым v1-стеком на той же машине); `build.network: host` в Dockerfile-билдах (DNS в контейнерах не резолвился из-за VPN на хосте — подтверждено воспроизводимо и на чужом контейнере, не специфично для проекта); ручной `chown -R www-data:www-data storage` после `docker compose up` вместо PUID/PGID (serversideup/php:8.4-fpm-nginx их не поддерживает); хостовый порт Postgres 5455 (5432/5433 заняты другими локальными сервисами на машине).
- deferred: —
- open_questions: —
